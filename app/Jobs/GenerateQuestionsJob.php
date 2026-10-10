<?php

namespace App\Jobs;

use App\Enums\AnalysisStep;
use App\Enums\StepStatus;
use App\Jobs\Concerns\AssertsQueueHeadroom;
use App\Models\AnalysisRun;
use App\Services\Ai\AnalysisRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 面接での逆質問（P6）を単独で生成する。
 *
 * P6 は分析パイプライン（P1〜P5）から外してある。面接に進まない企業でも
 * 毎回 codex を消費してしまうため、面接が決まってから個別に実行する。
 * 出力先は分析と同じ analysis_steps（step=p6）なので、表示・エクスポート・
 * ブリーフィングの経路は分岐させていない。
 *
 * 実行可否は「現在のソース有無」ではなく「対象ランの P2 に判定があるか」で決める。
 * 現在のソースで判定すると、分析後にソースを消した企業で生成済み逆質問をスキップで
 * 上書きして消したり、分析時に P2 がスキップされたランで空撃ちしたりする。
 *
 * 生成状態はこのステップ行の status で表す（Pending=待機 / Running=生成中 / Failed=失敗）。
 * 企業テーブルへのフラグ追加はしない。
 */
class GenerateQuestionsJob implements ShouldQueue
{
    use AssertsQueueHeadroom;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $analysisRunId)
    {
        $this->assertQueueHeadroom();
    }

    public function handle(AnalysisRunner $runner): void
    {
        $run = AnalysisRun::with(['company.sourceDocuments', 'company.officialMetrics'])
            ->findOrFail($this->analysisRunId);

        // 対象ランの P2 判定が材料。無ければ生成できない（コントローラが事前に弾くため、
        // ここに来るのは直 dispatch などの異常系。黙ってスキップせず失敗として明示する）
        $verdicts = $run->stepFor(AnalysisStep::P2)?->output_json['verdicts'] ?? [];
        if ($verdicts === []) {
            throw new \LogicException(
                "このランには主張検証（P2）の結果が無いため逆質問を生成できません: run={$run->id}"
            );
        }

        $step = $run->steps()->updateOrCreate(
            ['step' => AnalysisStep::P6->value],
            ['status' => StepStatus::Running, 'error' => null],
        );

        $output = $runner->runStep($run->company, AnalysisStep::P6, $this->priorOutputs($run));

        $step->update(['status' => StepStatus::Done, 'output_json' => $output]);
    }

    /**
     * P6 のプロンプトが参照する前段の出力（P2 の判定結果・P5 の充足判定）。
     * 分析時と同じ形（step値 => 出力配列）で渡す。スキップされたステップの
     * {skipped: true, reason} はパイプライン同様に空扱いにする（材料として渡さない）。
     *
     * @return array<string, array<string, mixed>>
     */
    private function priorOutputs(AnalysisRun $run): array
    {
        $prior = [];
        foreach (AnalysisStep::pipeline() as $step) {
            $output = $run->stepFor($step)?->output_json;
            if (is_array($output) && ! ($output['skipped'] ?? false)) {
                $prior[$step->value] = $output;
            }
        }

        return $prior;
    }

    public function failed(\Throwable $e): void
    {
        AnalysisRun::find($this->analysisRunId)
            ?->steps()
            ->where('step', AnalysisStep::P6->value)
            ->update(['status' => StepStatus::Failed->value, 'error' => $e->getMessage()]);
    }
}
