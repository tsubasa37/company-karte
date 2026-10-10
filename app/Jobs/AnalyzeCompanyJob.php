<?php

namespace App\Jobs;

use App\Actions\SplitReviewSourceAction;
use App\Enums\AnalysisStep;
use App\Enums\RunStatus;
use App\Enums\SourceType;
use App\Enums\StepStatus;
use App\Enums\VerdictPerspective;
use App\Jobs\Concerns\AssertsQueueHeadroom;
use App\Models\AnalysisRun;
use App\Models\AnalysisStepResult;
use App\Models\Company;
use App\Services\Ai\AiDecider;
use App\Services\Ai\AnalysisRunner;
use App\Services\Ai\QuoteVerifier;
use App\Services\Ai\RecommendationEvaluator;
use App\Services\Ai\ReviewJudgmentRunner;
use App\Services\Ai\VerdictConsensus;
use App\Services\AnalysisTimeoutEstimator;
use App\Services\MaterialFreshness;
use App\Services\SalaryBreakdownCalculator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 1社の分析を P1→RJ→P2〜P5 の順で実行し、各ステップ結果を analysis_steps に保存する
 * （P6 逆質問は分析に含めず GenerateQuestionsJob で個別生成。RJ は判定エンジンが設定済みのときだけ動く）。
 * どこかで失敗したら、そのステップと run を failed にして中断（後続は実行しない）。
 * サイレント失敗禁止のため例外は握り潰さず再送出する。
 */
class AnalyzeCompanyJob implements ShouldQueue
{
    use AssertsQueueHeadroom;
    use Queueable;

    public int $tries = 1;

    /**
     * ジョブ全体の締め切り。見積もりは AnalysisTimeoutEstimator（AI 1回の上限秒 × 呼び出し回数 + 余裕）。
     *
     * retry_after に収まらない見積もりは黙って切り詰めない。切り詰めると AI の実行中に SIGKILL され
     * 原因の分からない失敗ランだけが残り、超過を許すと別ワーカーが「死んだ」と誤認して拾い直し
     * 同じ分析が二重に走る。どちらも避けるため、収まらない構成は投入時に例外で止める
     * （画面では AnalysisGuard が押す前に同じ理由を出す）。
     */
    public int $timeout;

    public function __construct(public int $analysisRunId)
    {
        $estimator = app(AnalysisTimeoutEstimator::class);
        $run = AnalysisRun::find($analysisRunId);

        $this->timeout = $run ? $estimator->forRun($run) : $estimator->forPanel(1);
        $this->assertQueueHeadroom();
    }

    public function handle(
        AnalysisRunner $runner,
        RecommendationEvaluator $evaluator,
        SplitReviewSourceAction $splitReview,
        QuoteVerifier $quotes,
        VerdictConsensus $consensus,
        MaterialFreshness $freshness,
        AiDecider $decider,
        ReviewJudgmentRunner $judgments,
    ): void {
        $run = AnalysisRun::with(['company.sourceDocuments', 'company.officialMetrics'])
            ->findOrFail($this->analysisRunId);

        $run->update([
            'status' => RunStatus::Running,
            'started_at' => now(),
            // このランが見た材料を残す。実行中に投入・更新された材料と、実行後に削除された
            // 材料を後から見分けられるようにする（終了時刻の比較ではどちらも取りこぼす）
            'materials_json' => $freshness->snapshot($run->company),
            'error' => null,
        ]);

        /** @var array<string, array<string, mixed>> $prior */
        $prior = [];
        $current = null;

        try {
            // 未分割の口コミソースをここで確定させる（クリップ直後に分析を開始しても
            // キューの処理順に依存せず、P4 は常に分割済みアイテムを材料にできる）
            foreach ($run->company->sourceDocuments as $doc) {
                if ($doc->type === SourceType::Review && $doc->split_at === null) {
                    $splitReview->execute($doc);
                }
            }

            foreach (AnalysisStep::pipeline() as $step) {
                /** @var AnalysisStepResult|null $existing */
                $existing = $run->steps()->firstWhere('step', $step->value);

                // 続きから再開: 既に完了/スキップ済みのステップは再実行せず結果を活かす（codex消費を最小化）
                if ($existing && $existing->status === StepStatus::Done) {
                    $prior[$step->value] = $existing->output_json ?? [];

                    continue;
                }
                if ($existing && $existing->status === StepStatus::Skipped) {
                    $prior[$step->value] = [];

                    continue;
                }

                $run->update(['current_step' => $step]);

                /** @var AnalysisStepResult $current */
                $current = $run->steps()->updateOrCreate(
                    ['step' => $step->value],
                    ['status' => StepStatus::Running, 'error' => null],
                );

                // 素材が無いステップは codex を呼ばずスキップ（空欄で誤魔化さず skipped で明示）。
                // 理由は「何を投入すれば動くか」が分かる文言を AnalysisRunner から受け取る。
                // RJ は素材があっても、判定エンジン未設定・主張 0 件ならスキップする
                $skipReason = ! $runner->hasMaterialFor($run->company, $step)
                    ? $runner->skipReasonFor($run->company, $step)
                    : ($step === AnalysisStep::RJ ? $this->reviewJudgmentSkipReason($decider, $prior) : null);

                if ($skipReason !== null) {
                    $current->update([
                        'status' => StepStatus::Skipped,
                        'output_json' => ['skipped' => true, 'reason' => $skipReason],
                    ]);
                    $prior[$step->value] = [];

                    continue;
                }

                $output = match ($step) {
                    // RJ は生成ではなく判定 API で動く（runStep に渡すとプロンプトが無く止まる）
                    AnalysisStep::RJ => $judgments->run($run, $prior[AnalysisStep::P1->value]),
                    // P2 は観点の異なる判定者を人数ぶん独立に走らせ、PHP 側で1つに確定する
                    // （人数1なら判定者は Base の1人で、多重化前と同じ手順になる）
                    AnalysisStep::P2 => $this->runVerdictPanel($runner, $consensus, $run, $prior),
                    default => $runner->runStep($run->company, $step, $prior),
                };

                // P1 の引用が求人票原文に実在するかを PHP で照合し、作文された主張を落とす
                if ($step === AnalysisStep::P1) {
                    $output = $quotes->filter($output, $runner->jobPostingText($run->company));
                }

                // P3 の危険フレーズ検出も同じく原文照合する（作文された引用を残さない）。
                // 続けて基本給・実質時給を PHP で確定計算し salary_breakdown.computed に入れる
                // （AI には原文の数値を拾わせるだけで、暗算はさせない）
                if ($step === AnalysisStep::P3) {
                    $output = $quotes->filterRhetoric($output, $runner->jobPostingText($run->company));
                    $output = SalaryBreakdownCalculator::apply($output);
                }

                // 口コミ材料の投入/未投入件数は PHP 側の確定値を出力に残す（AIに数えさせない）
                if ($step === AnalysisStep::P4) {
                    $output['material_meta'] = $runner->reviewMaterialMeta();
                }

                // P5 完了時点で、充足判定(P5)と裏取り(P2)・具体性(P3)から
                // おすすめ度を PHP 側で機械的に確定し、出力と企業に反映する。
                if ($step === AnalysisStep::P5) {
                    $args = [
                        $output,
                        $prior[AnalysisStep::P2->value] ?? [],
                        $prior[AnalysisStep::P3->value] ?? [],
                        // 「求人票を検証したか」は現在のソース有無ではなく、このランで P1 が
                        // 実際に実行されたか（prior に出力があるか）で判定する。
                        // 現在素材で判定すると、P1 スキップのまま失敗したランに求人票を
                        // 足して再開したとき、何も検証していないのに◎を出せてしまう
                        ($prior[AnalysisStep::P1->value] ?? []) !== [],
                    ];
                    $rec = $evaluator->evaluate(...$args);
                    $output['recommendation'] = $rec->value;
                    // 判定の根拠も保存し、画面で「なぜこの判定か」を示す
                    $output['recommendation_reason'] = $evaluator->reasonFor(...$args);
                    $run->company->update(['recommendation' => $rec]);
                }

                $prior[$step->value] = $output;

                $current->update([
                    'status' => StepStatus::Done,
                    'output_json' => $output,
                ]);
            }

            $run->update([
                'status' => RunStatus::Done,
                'current_step' => null,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $current?->update([
                'status' => StepStatus::Failed,
                'error' => $e->getMessage(),
            ]);

            $run->update([
                'status' => RunStatus::Failed,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * 素材はあるが口コミの機械判定（RJ）を回さない理由。回すなら null。
     * 判定エンジンが未設定なのは機能オフで、失敗ではない（理由を残して skipped にする）。
     *
     * @param  array<string, array<string, mixed>>  $prior
     */
    private function reviewJudgmentSkipReason(AiDecider $decider, array $prior): ?string
    {
        if (! $decider->isConfigured()) {
            return '判定エンジン未設定（設定画面 → AIエンジン → 判定エンジン で有効化できる。オフの間は従来どおり新しい順の口コミを材料にする）';
        }

        if (($prior[AnalysisStep::P1->value]['claims'] ?? []) === []) {
            return '主張が 0 件のため、口コミと突き合わせる対象がない';
        }

        return null;
    }

    /**
     * P2（裏取り）を、観点の異なる判定者で人数ぶん独立に実行して1つに確定する。
     *
     * 判定者は互いの意見を見ない。相互に見せると同調して収束し、codex の呼び出し回数だけ
     * 増えて判定の多様性が失われるため、独立実行の結果を PHP 側（VerdictConsensus）で集計する。
     * 各判定者の生出力は analysis_opinions に残し、確定した判定の根拠を後から検証できるようにする。
     *
     * @param  array<string, array<string, mixed>>  $prior
     * @return array<string, mixed>
     */
    private function runVerdictPanel(
        AnalysisRunner $runner,
        VerdictConsensus $consensus,
        AnalysisRun $run,
        array $prior,
    ): array {
        $opinions = [];

        foreach (VerdictPerspective::forPanel($run->panel_size) as $i => $perspective) {
            $output = $runner->runStep($run->company, AnalysisStep::P2, $prior, $perspective);
            // 材料リンクの実在チェックは集計前に判定者ごとに済ませる
            // （作文された ID が票として集計に混ざらないようにする）
            $output = $this->filterSourceIds($output, $run->company);

            // 失敗ステップからの再開でも判定者の行が重複しないよう、パネル番号で上書きする
            $run->opinions()->updateOrCreate(
                ['step' => AnalysisStep::P2->value, 'panelist_index' => $i + 1],
                ['perspective' => $perspective->value, 'output_json' => $output],
            );

            $opinions[] = ['perspective' => $perspective, 'output' => $output];
        }

        return $consensus->merge($opinions);
    }

    /**
     * P2 の source_ids を、この企業に実在するソース ID だけに絞る。
     * 落とした件数は行ごとに残し、黙って捨てない（QuoteVerifier と同じ思想）。
     *
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function filterSourceIds(array $output, Company $company): array
    {
        $valid = $company->sourceDocuments->pluck('id')->flip();

        $output['verdicts'] = array_map(function (array $v) use ($valid) {
            $ids = array_values(array_filter(
                $v['source_ids'] ?? [],
                fn ($id) => is_int($id) && $valid->has($id),
            ));
            $dropped = count($v['source_ids'] ?? []) - count($ids);
            $v['source_ids'] = $ids;
            if ($dropped > 0) {
                $v['dropped_source_ids_count'] = $dropped;
            }

            return $v;
        }, $output['verdicts'] ?? []);

        return $output;
    }

    /**
     * timeout 等で handle 内の catch を経ずにジョブが失敗した場合も、run/step を
     * 失敗として確定する（サイレント失敗禁止）。handle 内で既に failed 済みの場合は
     * ステータス条件で弾き、二重更新を避ける。
     */
    public function failed(\Throwable $e): void
    {
        $run = AnalysisRun::find($this->analysisRunId);

        if (! $run || ! in_array($run->status, [RunStatus::Queued, RunStatus::Running], true)) {
            return;
        }

        $run->steps()
            ->where('status', StepStatus::Running->value)
            ->update([
                'status' => StepStatus::Failed->value,
                'error' => $e->getMessage(),
            ]);

        $run->update([
            'status' => RunStatus::Failed,
            'error' => $e->getMessage(),
            'finished_at' => now(),
        ]);
    }
}
