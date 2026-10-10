<?php

namespace App\Actions;

use App\Enums\AnalysisStep;
use App\Enums\CompanyStatus;
use App\Enums\IssueStatus;
use App\Enums\MetricSource;
use App\Enums\Recommendation;
use App\Enums\RunStatus;
use App\Enums\ScheduleKind;
use App\Enums\SourceType;
use App\Enums\StepStatus;
use App\Enums\VerdictPerspective;
use App\Models\AnalysisRun;
use App\Models\Company;
use App\Models\OpenIssue;
use App\Models\ScheduleEvent;
use App\Services\Ai\AnalysisRunner;
use App\Services\Ai\ClaimReviewAggregate;
use App\Services\Ai\VerdictConsensus;
use App\Services\AnalysisGuard;
use App\Services\BriefingStaleness;
use App\Services\MaterialCoverage;
use App\Services\MaterialFreshness;
use App\Services\MetricBenchmark;
use App\Services\ScheduleEventPresenter;
use App\Services\StuckRunDetector;
use App\Services\TextNormalizer;
use Illuminate\Support\Carbon;

/**
 * 企業詳細ページ（Companies/Show）に渡すデータを組み立てる。
 * カルテの整形・前回ランとの差分集計はここで確定させ、画面側では計算しない。
 */
class BuildCompanyPageAction
{
    /** ソース一覧に出す抜粋の文字数 */
    private const EXCERPT_CHARS = 120;

    /**
     * P2 判定の表示順（注意すべきものを先に）。
     * split（判定者の意見が分かれた）は材料を足しても解消せず面接で確認するしかないため、
     * 材料不足の unverifiable ではなく矛盾の直後に置く。
     */
    private const VERDICT_ORDER = [
        'contradict' => 0,
        VerdictConsensus::SPLIT => 1,
        'match' => 2,
        'unverifiable' => 3,
    ];

    /** P6 逆質問・P1 主張の重要度の表示順（高いものを先に） */
    private const PRIORITY_ORDER = ['high' => 0, 'mid' => 1, 'low' => 2];

    /** 重要度が引き当てられなかった主張の順位（黙って mid 扱いにせず末尾へ置く） */
    private const IMPORTANCE_UNKNOWN_RANK = 3;

    /** 判定変化リストの表示件数（残りは件数だけ出す） */
    private const CHANGE_LIMIT = 8;

    /** 判定変化リストの表示文言（Vue 側で組み立てない） */
    private const VERDICT_LABEL = [
        'match' => '整合',
        'contradict' => '矛盾',
        VerdictConsensus::SPLIT => '判定割れ',
        'unverifiable' => '検証不能',
    ];

    public function __construct(
        private readonly AnalysisGuard $guard,
        private readonly BriefingStaleness $staleness,
        private readonly ScheduleEventPresenter $presenter,
        private readonly MaterialCoverage $coverage,
        private readonly MaterialFreshness $freshness,
        private readonly MetricBenchmark $benchmark,
        private readonly StuckRunDetector $stuck,
        private readonly ClaimReviewAggregate $machine,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(Company $company): array
    {
        $company->load([
            'sourceDocuments' => fn ($q) => $q
                ->withCount(['reviewItems', 'revisions'])
                ->latest('created_at'),
            'officialMetrics',
            'scheduleEvents' => fn ($q) => $q->chronological(),
            // 追跡中の論点は「未確認 → 未解消 → 懸念が強まった → 解消 → 取り下げ」の順で見たいので
            // 状態の並びは PHP（openIssues）で確定する
            'openIssues' => fn ($q) => $q->with('resolvedSource:id,type')->orderBy('id'),
            // 材料の充足度は件数しか見ないので、本文を持たない列だけ読む
            'reviewItems:id,company_id',
        ]);
        // 予定の整形で $event->company を読むため、逆リレーションを手で張って N+1 を止める
        $company->scheduleEvents->each(fn (ScheduleEvent $e) => $e->setRelation('company', $company));

        $run = $company->latestRun;
        $run?->load('steps');

        return [
            'company' => $this->company($company),
            'sources' => $this->sources($company),
            'officialMetrics' => $this->officialMetrics($company),
            // この企業の選考予定（時系列）。登録・編集は予定表と同じルートに投げる
            'scheduleEvents' => $company->scheduleEvents->map($this->presenter->present(...))->all(),
            'scheduleKindOptions' => ScheduleKind::options(),
            'run' => $this->runMeta($run),
            // カルテは「最後に完了したラン」を表示する。実行中・失敗の最新ランで
            // 完成済みのカルテを消さない（どのランの表示かは karte.runId で画面に明示する）
            'karte' => $this->karte($this->displayRun($company, $run), $company),
            // 面接で確認する論点（P2 から追跡対象にしたもの）。カルテとは別に保持し、再分析で消えない
            'openIssues' => $this->openIssues($company),
            'issueStatusOptions' => IssueStatus::options(),
            // 「判定を固めるのにあと何が要るか」。AI は使わず投入済みの材料を数えるだけ
            'coverage' => $this->coverage->evaluate($company),
            // 公式数値の、登録済み他社の中での位置（業界平均ではない）
            'benchmark' => $this->benchmark->forCompany($company),
            'briefing' => $this->briefing($company),
            'edinet' => $this->edinet($company),
            // 押す前から「なぜ分析できないか」を出すための判定（実行可能なら null）
            'analyzeBlocked' => $this->guard->blockInfo($company),
            'statusOptions' => CompanyStatus::options(),
            'sourceTypeOptions' => SourceType::options(),
            'metricSourceOptions' => MetricSource::options(),
            // 分析実行時に選ぶ「裏取りの判定者の人数」の選択肢（文言は Enum が確定する）
            'panelSizeOptions' => VerdictPerspective::panelOptions(),
        ];
    }

    /** カルテとして表示するラン = 最新ランが done ならそれ、でなければ直近の done ラン */
    private function displayRun(Company $company, ?AnalysisRun $latest): ?AnalysisRun
    {
        if ($latest && $latest->status === RunStatus::Done) {
            return $latest;
        }

        $done = $company->analysisRuns()
            ->where('status', RunStatus::Done)
            ->latest('id')
            ->first();
        $done?->load('steps');

        return $done;
    }

    /**
     * @return array<string, mixed>
     */
    private function company(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'listed' => $company->listed,
            'position' => $company->position,
            'legalNumber' => $company->legal_number,
            // EDINET 取得成功時にキャッシュされるコード（未取得は null）
            'edinetCode' => $company->edinet_code,
            'status' => $company->status->value,
            'statusLabel' => $company->status->label(),
            'recommendation' => $company->recommendation?->value,
            'recommendationLabel' => $company->recommendation?->label(),
            'recommendationSymbol' => $company->recommendation?->symbol(),
            'selectionNotes' => $company->selection_notes,
            // オファー回答期限（input[type=date] にそのまま入る形で渡す）。面接日程は scheduleEvents
            'offerDeadline' => $company->offer_deadline?->format('Y-m-d'),
            'offerAnnualSalary' => $company->offer_json['annual_salary'] ?? null,
            'offerNotes' => $company->offer_json['notes'] ?? null,
            // オファー額の実質化用の内訳（PHP側の計算と画面表示にのみ使う。codex には渡さない）
            'offerBaseMonthlyYen' => $company->offer_json['base_monthly_yen'] ?? null,
            'offerFixedOvertimeHours' => $company->offer_json['fixed_overtime_hours'] ?? null,
            'offerBonusMonths' => $company->offer_json['bonus_months'] ?? null,
            'laborNote' => $company->labor_note,
            // 「未突合」と「突合済み・該当なし」を画面で区別するために日時を必ず渡す
            // （laborNote が null なだけでは、突合していないのか一致が無かったのか分からない）
            'laborCheckedAt' => $company->labor_checked_at?->format('Y-m-d H:i'),
            'laborNoteHistory' => $this->laborNoteHistory($company),
        ];
    }

    /**
     * 過去に公表リストへ一致していた記録（新しい順）。
     * 公表リストは掲載期間でローテーションするため、注記が消えた事実だけを持たせる。
     *
     * @return array<int, array<string, string|null>>
     */
    private function laborNoteHistory(Company $company): array
    {
        return collect($company->labor_note_history ?? [])
            ->map(fn (array $entry) => [
                'note' => (string) ($entry['note'] ?? ''),
                'reason' => (string) ($entry['reason'] ?? ''),
                // 書き込み側（LaborViolationChecker）が ISO8601 で入れる。壊れた値は例外にする
                'clearedAt' => isset($entry['cleared_at'])
                    ? Carbon::parse((string) $entry['cleared_at'])->format('Y-m-d H:i')
                    : null,
            ])
            ->reverse()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sources(Company $company): array
    {
        return $company->sourceDocuments->map(function ($s) {
            // 切り捨て判定は分析側の limitFor と同一（判定箇所を分析側の1つに寄せる）。
            // 口コミは分割済みアイテムが材料なので raw_text の長さでは警告しない。
            $limit = AnalysisRunner::limitFor($s->type);
            $isReview = $s->type === SourceType::Review;

            return [
                'id' => $s->id,
                'type' => $s->type->value,
                'typeLabel' => $s->type->label(),
                'origin' => $s->origin->value,
                'url' => $s->url,
                'host' => $s->url ? (parse_url($s->url, PHP_URL_HOST) ?: null) : null,
                // クリップ時のページタイトルと AI の一行要約（画面フォーム投入などで無ければ null）
                'title' => $s->title,
                'summary' => $s->summary,
                'excerpt' => mb_substr((string) $s->raw_text, 0, self::EXCERPT_CHARS),
                // 本文の長さ（全文はソース詳細ページで表示するため、ここでは渡さない）
                'length' => mb_strlen((string) $s->raw_text),
                'limit' => $limit,
                'isTruncated' => ! $isReview && mb_strlen((string) $s->raw_text) > $limit,
                'fetchedAt' => $s->fetched_at?->format('Y-m-d H:i'),
                'createdAt' => $s->created_at?->format('Y-m-d H:i'),
                'isReview' => $isReview,
                'splitAt' => $isReview ? $s->split_at?->format('Y-m-d H:i') : null,
                'reviewItemCount' => $isReview ? $s->review_items_count : null,
                'revisionCount' => $s->revisions_count,
            ];
        })->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function officialMetrics(Company $company): array
    {
        return $company->officialMetrics->map(fn ($m) => [
            'id' => $m->id,
            'sourceLabel' => $m->source->label(),
            'sourceUrl' => $m->source_url,
            'key' => $m->key,
            'value' => $m->value,
            'unit' => $m->unit,
            'fiscalYear' => $m->fiscal_year,
        ])->all();
    }

    /**
     * 追跡中の論点（面接で確認する論点）。状態の並び順もここで確定させる。
     *
     * @return array<string, mixed>
     */
    private function openIssues(Company $company): array
    {
        $issues = $company->openIssues
            ->sortBy([
                // 未確認・未解消を先に、解消済み・取り下げを後ろに
                fn (OpenIssue $i) => $i->status->isPending() ? 0 : 1,
                fn (OpenIssue $i) => $i->id,
            ])
            ->values()
            ->map(fn (OpenIssue $i) => [
                'id' => $i->id,
                'claim' => $i->claim,
                // 登録時点の判定（その後の再分析で判定が変わっても、追跡を始めた理由として残す）
                'verdict' => $i->verdict,
                'verdictLabel' => $this->verdictLabel($i->verdict),
                'rationale' => $i->rationale,
                'status' => $i->status->value,
                'statusLabel' => $i->status->label(),
                'isPending' => $i->status->isPending(),
                'resolutionNote' => $i->resolution_note,
                // 状態の根拠が面接メモなら、そのソース詳細へ辿れるようにする（手動変更なら null）
                'resolvedSourceId' => $i->resolved_source_document_id,
                'statusUpdatedAt' => $i->status_updated_at?->format('Y-m-d H:i'),
                'matchedAt' => $i->matched_at?->format('Y-m-d H:i'),
            ])
            ->all();

        return [
            'items' => $issues,
            // 「まだ面接で確認すべき件数」。画面側で数え直さない
            'pendingCount' => count(array_filter($issues, fn (array $i) => $i['isPending'])),
        ];
    }

    /**
     * P2 の判定行に「既に論点として追跡しているか」を付ける。
     * 画面はこれを見て「論点として追う」ボタンを出すか、追跡中の表示にするかを決める。
     *
     * @param  array<string, mixed>  $karte
     */
    private function markTrackedIssues(array &$karte, Company $company): void
    {
        $verdicts = $karte['p2']['verdicts'] ?? null;

        if (! is_array($verdicts)) {
            return;
        }

        $tracked = $company->openIssues->keyBy(fn (OpenIssue $i) => $i->claim_hash);

        foreach ($verdicts as $i => $verdict) {
            $claim = (string) ($verdict['claim'] ?? '');
            $issue = $claim === '' ? null : $tracked->get(OpenIssue::hashFor($claim));

            // 追跡中でなければ null（画面は null のときだけ「論点として追う」を出す）
            $karte['p2']['verdicts'][$i]['issueId'] = $issue?->id;
            // 状態も渡す。面接での回答が根拠として紐づいた論点を、テーブルから消させないため
            $karte['p2']['verdicts'][$i]['issueStatus'] = $issue?->status->value;
        }
    }

    /**
     * P2 の判定行に、同じランの口コミの機械判定（RJ）の集計を付ける。
     * 集計はランにつき 1 回だけ取り、claim_index で引き当てる（行ごとに引かない）。
     * 対応する集計が無い行（claim_index の無い旧ラン・判定していない主張）は null。
     *
     * @param  array<string, mixed>  $karte
     */
    private function markMachineJudgments(array &$karte, AnalysisRun $run): void
    {
        $aggregates = $this->machine->forRun($run);
        // RJ を回していないランでは、画面は列ごと出さない（表の見た目を変えない）
        $karte['hasMachineJudgment'] = $aggregates !== [];

        if (! is_array($karte['p2']['verdicts'] ?? null)) {
            return;
        }

        foreach ($karte['p2']['verdicts'] as $i => $verdict) {
            $index = $verdict['claim_index'] ?? null;
            $aggregate = is_int($index) ? ($aggregates[$index] ?? null) : null;

            $karte['p2']['verdicts'][$i]['machine'] = $aggregate === null ? null : [
                'summary' => $aggregate['summary'],
                'counts' => [
                    'total' => $aggregate['total'],
                    'contradict' => $aggregate['contradict'],
                    'support' => $aggregate['support'],
                    'none' => $aggregate['none'],
                    'unsure' => $aggregate['unsure'],
                ],
                'contradictItems' => $aggregate['contradictItems'],
                'supportItems' => $aggregate['supportItems'],
                'flag' => $this->machine->flagFor((string) ($verdict['verdict'] ?? ''), $aggregate),
            ];
        }
    }

    /**
     * 面接前ブリーフィングの表示用データ。
     *
     * @return array<string, mixed>
     */
    private function briefing(Company $company): array
    {
        return [
            'data' => $company->briefing_json,
            'generatedAt' => $company->briefing_generated_at?->format('Y-m-d H:i'),
            // requested_at は完了・失敗で null に戻るため、立っている間が「生成中」
            'isGenerating' => $company->briefing_requested_at !== null,
            'error' => $company->briefing_error,
            // 生成後にカルテ・面接記録が更新された場合の陳腐化理由（未生成・最新なら null）
            'staleReason' => $this->staleness->reasonFor($company),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function edinet(Company $company): array
    {
        return [
            'isFetching' => $company->edinet_requested_at !== null,
            'fetchedAt' => $company->edinet_fetched_at?->format('Y-m-d H:i'),
            'error' => $company->edinet_error,
            // 押す前から「なぜ取得できないか」を出す（EdinetController と同じ前提条件）
            'blockedReason' => $this->edinetBlockedReason($company),
            'gbizBlockedReason' => $this->gbizBlockedReason($company),
        ];
    }

    /**
     * gBizINFO 取得の前提条件。EDINET と同じく「押してから失敗」ではなく
     * 押す前に理由を出す（トークン未設定のまま押せると、失敗エラーだけが残って混乱する）。
     */
    private function gbizBlockedReason(Company $company): ?string
    {
        if (blank($company->legal_number)) {
            return '法人番号が未設定です。基本情報タブで入力してください。';
        }
        if (blank(config('services.gbizinfo.token'))) {
            return 'GBIZINFO_API_TOKEN が未設定です（https://info.gbiz.go.jp/hojin/ の「Web-API利用申請」から無料発行し .env に設定してください）。';
        }

        return null;
    }

    private function edinetBlockedReason(Company $company): ?string
    {
        if (! $company->listed) {
            return 'EDINET（有報）は上場企業のみ。基本情報タブで上場フラグを確認してください。';
        }
        if (blank($company->legal_number)) {
            return '法人番号が未設定です。基本情報タブで入力してください。';
        }
        if (blank(config('services.edinet.key'))) {
            return 'EDINET_API_KEY が未設定です（.env に設定してください）。';
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function runMeta(?AnalysisRun $run): ?array
    {
        if (! $run) {
            return null;
        }

        return [
            'id' => $run->id,
            'status' => $run->status->value,
            'statusLabel' => $run->status->label(),
            'isRunning' => in_array($run->status, [RunStatus::Queued, RunStatus::Running], true),
            // 実行中のまま更新が途絶えたら、画面から中断できるようにする（判定は StuckRunDetector）
            'isStuck' => $this->stuck->isStuck($run),
            'lastActivityAt' => $this->stuck->lastActivityAt($run)?->format('Y-m-d H:i'),
            'stuckAfterMinutes' => (int) ceil($this->stuck->thresholdSeconds() / 60),
            'currentStepLabel' => $run->current_step?->label(),
            // このランを何人で裏取りしたか（人数セレクタの初期値と進捗表示に使う）
            'panelSize' => $run->panel_size,
            'error' => $run->error,
            'steps' => $run->steps
                // RJ のスキップ（判定エンジン未設定など）は出さない。オフのままなら進捗の見た目を変えない
                ->reject(fn ($s) => $s->step === AnalysisStep::RJ && $s->status === StepStatus::Skipped)
                // 実行順に並べる（値の文字列順だと rj が p5 の後ろに来る）
                ->sortBy(fn ($s) => array_search($s->step, AnalysisStep::all(), true))
                ->map(fn ($s) => [
                    'step' => $s->step->value,
                    'label' => $s->step->label(),
                    'status' => $s->status->value,
                    'statusLabel' => $s->status->label(),
                ])
                ->values(),
        ];
    }

    /**
     * 完了したランのステップ出力を step値キーのカルテ配列にして返す。
     *
     * @return array<string, mixed>|null
     */
    private function karte(?AnalysisRun $run, Company $company): ?array
    {
        if (! $run) {
            return null;
        }

        $karte = [];
        $skipped = [];
        // 自動実行しない P6（逆質問）も表示対象。RJ の確率は載せない（集計は markMachineJudgments が付ける）
        foreach (AnalysisStep::karte() as $step) {
            $out = $run->stepFor($step)?->output_json;

            // スキップされたステップは「無い」のではなく「理由がある空」。理由を画面に出す
            if (is_array($out) && ($out['skipped'] ?? false)) {
                $karte[$step->value] = null;
                $skipped[$step->value] = (string) ($out['reason'] ?? '素材未投入');

                continue;
            }
            $karte[$step->value] = $out;
        }

        $this->sortForDisplay($karte);
        $this->markTrackedIssues($karte, $company);
        $this->markMachineJudgments($karte, $run);

        // おすすめ度の表示文言は Recommendation enum を唯一の正とし、画面側で組み立てない
        $karte['recommendation'] = $this->recommendation($run);
        $karte['recommendationReason'] = $this->recommendationReason($run);
        $karte['diff'] = $this->diffMeta($run);
        // 空のときも JSON でオブジェクト（{}）になるよう object キャスト（TS 側は Record 宣言。[] だと配列になり型が食い違う）
        $karte['skipped'] = (object) $skipped;
        $karte['verdictCounts'] = $this->verdictCounts($run);
        // 充足判定の根拠に、判定が割れた主張が混ざっている可能性を注意喚起する
        $karte['checkSplitNote'] = $this->checkSplitNote($karte);
        // 引用チェックの分母（0除外時に「全件一致」と言うための件数）
        $karte['claimCount'] = count($karte['p1']['claims'] ?? []);
        // どのランのカルテかを常に明示する（最新ランが実行中・失敗のときのバナー判定に使う）
        $karte['runId'] = $run->id;
        // このカルテを何人の判定者で裏取りしたか。判定が割れなかった場合、1人で見た結果と
        // 3人が一致した結果は画面上で見分けが付かないため、カルテ自身に人数を持たせる
        $karte['panelSize'] = $run->panel_size;
        $karte['finishedAt'] = $run->finished_at?->format('Y-m-d H:i');
        // 「分析後にソース・公的数値が増えている（カルテが古い）」をカルテ画面にも出す
        $karte['freshness'] = $this->freshness->compare($company, $run);
        // 逆質問は分析に含めず個別生成なので、その生成状態を画面に渡す
        $karte['questions'] = $this->questionsState($run);

        return $karte;
    }

    /**
     * 逆質問（P6）の生成状態。分析パイプラインから外してあるため、
     * 「未生成 / 生成中 / 失敗」を画面が区別できるようにステップ行の状態を渡す。
     *
     * @return array{isGenerating: bool, error: string|null, generated: bool}
     */
    private function questionsState(AnalysisRun $run): array
    {
        $step = $run->stepFor(AnalysisStep::P6);

        return [
            // Pending も「生成中」（dispatch 時に立ててキュー待機中の連打を防ぐ）
            'isGenerating' => in_array($step?->status, [StepStatus::Pending, StepStatus::Running], true),
            'error' => $step?->status === StepStatus::Failed ? $step->error : null,
            // Done / Skipped は「一度実行した」= 再生成ボタンの文言を変える判断に使う
            'generated' => in_array($step?->status, [StepStatus::Done, StepStatus::Skipped], true),
        ];
    }

    /**
     * 表示順の確定（並べ替えは PHP、画面は描画のみ）。
     * P2 は 第1キー=判定（矛盾→整合→検証不能）・第2キー=P1 の重要度（high→mid→low）、
     * P6 は優先度の高い順。
     *
     * @param  array<string, mixed>  $karte
     */
    private function sortForDisplay(array &$karte): void
    {
        $importance = $this->importanceByIndex($karte['p1'] ?? null);
        // importance が無い旧ラン・claim_index が無い旧ランは並び順を変えず、その事実を画面に出す（mid 等で埋めない）
        $karte['importanceNote'] = $this->importanceNote($karte['p1'] ?? null, $karte['p2'] ?? null, $importance);

        if (is_array($karte['p2']['verdicts'] ?? null)) {
            $karte['p2']['verdicts'] = collect($karte['p2']['verdicts'])
                // 並べ替えると行の位置が保存済み output_json の添字とずれる。論点の登録は
                // 「保存されている何番目の判定か」で指定するので、並べ替える前に元の添字を持たせる
                ->map(fn ($v, int $i) => $v + ['sourceIndex' => $i])
                ->map(fn ($v) => $importance === null ? $v : $v + [
                    // P2 が引用した P1 主張の添字（claim_index）で重要度を引き当てる。
                    // 主張テキストの照合は P1（要約）と P2（原文引用優先）の文体差で全滅した実績があるため使わない。
                    // 添字が範囲外・null のものは null のまま渡し、画面が「重要度なし」と明示する
                    'importance' => $importance[$v['claim_index'] ?? null] ?? null,
                ])
                ->sortBy(fn ($v) => (self::VERDICT_ORDER[$v['verdict'] ?? ''] ?? 3) * 10
                    + (self::PRIORITY_ORDER[$v['importance'] ?? ''] ?? self::IMPORTANCE_UNKNOWN_RANK))
                ->values()
                ->all();
        }
        if (is_array($karte['p6']['questions'] ?? null)) {
            $karte['p6']['questions'] = collect($karte['p6']['questions'])
                ->sortBy(fn ($q) => self::PRIORITY_ORDER[$q['priority'] ?? ''] ?? 3)
                ->values()
                ->all();
        }
    }

    /**
     * P1 の「claims 配列の添字 => 重要度」の対応表。
     * 1件でも importance が欠けていれば null を返し、欠けた分を mid 等で埋めない
     * （埋めると「未保存」と「実際に中位」が区別できなくなる）。
     *
     * @return array<int, string>|null
     */
    private function importanceByIndex(mixed $p1): ?array
    {
        $claims = is_array($p1) ? ($p1['claims'] ?? null) : null;
        if (! is_array($claims) || $claims === []) {
            return null;
        }

        $map = [];
        foreach (array_values($claims) as $i => $claim) {
            $importance = $claim['importance'] ?? null;
            if (! is_string($importance) || ! isset(self::PRIORITY_ORDER[$importance])) {
                return null;
            }
            $map[$i] = $importance;
        }

        return $map;
    }

    /**
     * 重要度が使えない理由（使えていれば null）。主張が0件のときは順位付けの対象が無いので出さない。
     * P1 に importance が無い旧ランと、P2 に claim_index が無い旧ランの両方を区別して明示する。
     *
     * @param  array<int, string>|null  $importance
     */
    private function importanceNote(mixed $p1, mixed $p2, ?array $importance): ?string
    {
        $claims = is_array($p1) ? ($p1['claims'] ?? null) : null;
        if (! is_array($claims) || $claims === []) {
            return null;
        }

        if ($importance === null) {
            return '主張の重要度はこのランには未保存です（並び順は判定のみ。再分析すると重要度の高い主張が先頭に並びます）';
        }

        // P1 に重要度はあるが、P2 側に引き当てるための claim_index が1件も無い旧ラン
        $verdicts = is_array($p2) ? ($p2['verdicts'] ?? null) : null;
        if (is_array($verdicts) && $verdicts !== []
            && collect($verdicts)->every(fn ($v) => ! array_key_exists('claim_index', $v))) {
            return '主張と重要度の対応がこのランには未保存です（並び順は判定のみ。再分析すると重要度の高い主張が先頭に並びます）';
        }

        return null;
    }

    /**
     * 直前の done ランとの差分（おすすめ度の変化・P2判定件数の増減）。前ランが無ければ null。
     *
     * @return array<string, mixed>|null
     */
    private function diffMeta(AnalysisRun $run): ?array
    {
        $prev = $run->company->analysisRuns()
            ->where('status', RunStatus::Done)
            ->where('id', '<', $run->id)
            ->latest('id')
            ->first();

        if (! $prev) {
            return null;
        }

        $prev->load('steps');

        return [
            'prevRecommendation' => $this->recommendation($prev),
            'currRecommendation' => $this->recommendation($run),
            'prev' => $this->verdictCounts($prev),
            'curr' => $this->verdictCounts($run),
            // 件数の増減だけでは「どの主張の判定が変わったか」が分からないため変化リストも出す
            'changes' => $this->verdictChanges($prev, $run),
            // 人数が違うランを比べると、判定の変化に確定ルールの違いが混ざる
            'panelNote' => $this->panelNote($prev, $run),
        ];
    }

    /**
     * 前回と裏取りの人数が違う場合の注記（同じ人数なら null）。
     * 人数が変わると過半数の条件も変わるため、判定の変化を「意見が変わった」とだけ読ませない。
     */
    private function panelNote(AnalysisRun $prev, AnalysisRun $curr): ?string
    {
        if ($prev->panel_size === $curr->panel_size) {
            return null;
        }

        return "裏取りの人数が前回と違います（前回{$prev->panel_size}人 → 今回{$curr->panel_size}人）。"
            .'判定の変化には、意見の変化だけでなく確定ルール（過半数の条件）の違いも含まれます。';
    }

    /**
     * 主張テキストをキーにした判定の変化リスト（前回 => 今回）。
     * 比較できないランは items を空にし、その理由を unavailableReason に入れる。
     *
     * @return array<string, mixed>
     */
    private function verdictChanges(AnalysisRun $prev, AnalysisRun $curr): array
    {
        $prevMap = $this->verdictMap($prev);
        $currMap = $this->verdictMap($curr);

        if ($prevMap === null || $currMap === null) {
            return [
                'items' => [],
                'total' => 0,
                'more' => 0,
                'unavailableReason' => $prevMap === null
                    ? '前回のランに主張検証（P2）の結果が無いため、判定の変化は比較できません。'
                    : '今回のランに主張検証（P2）の結果が無いため、判定の変化は比較できません。',
            ];
        }

        $items = [];
        foreach ($currMap as $key => $current) {
            $before = $prevMap[$key] ?? null;
            if ($before !== null && $before['verdict'] === $current['verdict']) {
                continue;
            }
            $items[] = [
                'claim' => $current['claim'],
                'prevLabel' => $before === null ? '（前回は未抽出）' : $this->verdictLabel($before['verdict']),
                'currLabel' => $this->verdictLabel($current['verdict']),
                'rank' => self::VERDICT_ORDER[$current['verdict']] ?? 3,
            ];
        }
        // 今回消えた主張（求人票の差し替え等で抽出されなくなった）も黙って落とさない
        foreach ($prevMap as $key => $before) {
            if (! isset($currMap[$key])) {
                $items[] = [
                    'claim' => $before['claim'],
                    'prevLabel' => $this->verdictLabel($before['verdict']),
                    'currLabel' => '（今回は未抽出）',
                    'rank' => 4,
                ];
            }
        }

        $sorted = collect($items)->sortBy('rank')->values();

        return [
            'items' => $sorted->take(self::CHANGE_LIMIT)
                ->map(fn (array $i) => collect($i)->except('rank')->all())
                ->all(),
            'total' => $sorted->count(),
            'more' => max(0, $sorted->count() - self::CHANGE_LIMIT),
            'unavailableReason' => null,
        ];
    }

    /**
     * ランの P2 判定を「照合用に正規化した主張テキスト => 判定」にする（P2 が無ければ null）。
     * 同じ主張が複数行あるランでは最初の行を採る。
     *
     * @return array<string, array{claim: string, verdict: string}>|null
     */
    private function verdictMap(AnalysisRun $run): ?array
    {
        $p2 = $run->stepFor(AnalysisStep::P2)?->output_json;
        $verdicts = is_array($p2) ? ($p2['verdicts'] ?? null) : null;

        if (! is_array($verdicts)) {
            return null;
        }

        $map = [];
        foreach ($verdicts as $verdict) {
            $claim = (string) ($verdict['claim'] ?? '');
            $key = TextNormalizer::normalize($claim);
            if ($key === '' || isset($map[$key])) {
                continue;
            }
            $map[$key] = ['claim' => $claim, 'verdict' => (string) ($verdict['verdict'] ?? '')];
        }

        return $map;
    }

    /** 判定の表示文言（スキーマ外の値が入っていたら値そのものを見せる） */
    private function verdictLabel(string $verdict): string
    {
        return self::VERDICT_LABEL[$verdict] ?? $verdict;
    }

    /**
     * ランのおすすめ度を value/label/symbol の組で返す（未判定は null）。
     *
     * @return array<string, string>|null
     */
    private function recommendation(AnalysisRun $run): ?array
    {
        $p5 = $run->stepFor(AnalysisStep::P5)?->output_json;
        $value = is_array($p5) ? ($p5['recommendation'] ?? null) : null;
        $rec = is_string($value) ? Recommendation::tryFrom($value) : null;

        return $rec === null ? null : [
            'value' => $rec->value,
            'label' => $rec->label(),
            'symbol' => $rec->symbol(),
        ];
    }

    /** 判定根拠（PHPが確定した文言。未保存なら null） */
    private function recommendationReason(AnalysisRun $run): ?string
    {
        $p5 = $run->stepFor(AnalysisStep::P5)?->output_json;
        $reason = is_array($p5) ? ($p5['recommendation_reason'] ?? null) : null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * 充足判定（P5）の表示に添える注意書き。判定割れが無ければ null。
     *
     * 必須条件の ✅ は codex がプロンプトの指示に従って出す値で、PHP 側に照合手段が無い
     * （evidence は自由文で、主張テキストとの機械照合は文体差で成立しない）。
     * おすすめ度は判定割れがあれば Mid に落ちるが、個別条件の ✅ はそのまま表示されるため、
     * 「総合は要確認なのに個別条件は充足に見える」状態になりうる。
     * ✅ を機械的に打ち消すと今度は正しい充足まで潰すので、根拠の確認を促すに留める。
     *
     * @param  array<string, mixed>  $karte
     */
    private function checkSplitNote(array $karte): ?string
    {
        $split = $karte['verdictCounts'][VerdictConsensus::SPLIT];

        if ($split === 0 || ($karte['p5'] ?? null) === null) {
            return null;
        }

        return "主張検証で判定が割れた項目が{$split}件あります。"
            .'充足判定の根拠が、判定の割れた主張に依存していないか根拠欄を確認してください。';
    }

    /**
     * @return array<string, int>
     */
    private function verdictCounts(AnalysisRun $run): array
    {
        $p2 = $run->stepFor(AnalysisStep::P2)?->output_json;
        $verdicts = is_array($p2) ? ($p2['verdicts'] ?? []) : [];

        // split は材料不足と混ぜず独立して数える（次に取る行動が「材料を足す」ではなく「面接で聞く」のため）
        $counts = ['match' => 0, 'contradict' => 0, VerdictConsensus::SPLIT => 0, 'unverifiable' => 0];
        foreach ($verdicts as $v) {
            $key = $v['verdict'] ?? null;
            if (isset($counts[$key])) {
                $counts[$key]++;
            }
        }

        return $counts;
    }
}
