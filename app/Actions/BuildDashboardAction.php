<?php

namespace App\Actions;

use App\Enums\AnalysisStep;
use App\Enums\CompanyStatus;
use App\Enums\Recommendation;
use App\Enums\RunStatus;
use App\Enums\SourceType;
use App\Enums\StepStatus;
use App\Models\Company;
use App\Models\MailIngest;
use App\Models\OpenIssue;
use App\Models\Profile;
use App\Models\ScheduleEvent;
use App\Models\SourceDocument;
use App\Services\Ai\AiSettings;
use App\Services\MaterialCoverage;
use App\Services\MaterialFreshness;
use App\Services\StuckRunDetector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 選考の全体状況（ダッシュボード）を組み立てる。集計はPHPで確定計算する。
 * 自分用途で企業数が限られるため全件走査で足りる。
 */
class BuildDashboardAction
{
    /** 表示順: 動いている選考を先に */
    private const STATUS_ORDER = [
        CompanyStatus::Interview,
        CompanyStatus::Offer,
        CompanyStatus::Applied,
        CompanyStatus::Casual,
        CompanyStatus::Kininaru,
        CompanyStatus::Declined,
    ];

    /** 選考が動いている（= 悪いニュースに気付けないと困る）ステータス */
    private const ACTIVE_STATUSES = [
        CompanyStatus::Casual,
        CompanyStatus::Applied,
        CompanyStatus::Interview,
        CompanyStatus::Offer,
    ];

    /** 労基突合の注記を一覧で見せるときの抜粋長 */
    private const NOTE_EXCERPT = 120;

    /** 失敗理由を一覧で見せるときの抜粋長 */
    private const ERROR_EXCERPT = 160;

    /** 未処理のままこの分数を超えたジョブは「ワーカー停止の疑い」として知らせる */
    private const STALLED_MINUTES = 10;

    /** アラート各ブロックに出す上位件数（残りは「他N件」に畳む） */
    private const ALERT_ITEM_LIMIT = 8;

    /** 期限・次回面接がこの日数以内に迫ったらダッシュボードに出す（過ぎたものは常に出す） */
    private const DEADLINE_SOON_DAYS = 7;

    /** 「最近取り込んだニュース」として出す日数 */
    private const NEWS_RECENT_DAYS = 14;

    /** 選考中企業でニュース材料がこの日数以上更新されていなければ確認を促す */
    private const NEWS_CHECK_DAYS = 7;

    /** タイトル未保存のニュースを一覧に出すときの本文抜粋長 */
    private const NEWS_EXCERPT = 60;

    /**
     * 警告文に出すバックアップ停滞の日数。停滞判定そのものは
     * BuildBackupStatusAction::STALE_DAYS が持つ（同 const は private なので文言用の写し）。
     */
    private const BACKUP_STALE_DAYS = 7;

    /** 全社走査は複数propsから参照されるので1回だけ実行する（メモ化） */
    private ?Collection $companies = null;

    private ?Profile $profile = null;

    /** Profile::first() は null もありうるため、問い合わせ済みかを別に持つ */
    private bool $profileLoaded = false;

    public function __construct(
        private readonly BuildBackupStatusAction $backupStatus,
        private readonly MaterialCoverage $coverage,
        private readonly MaterialFreshness $freshness,
        private readonly StuckRunDetector $stuck,
        private readonly AiSettings $aiSettings,
    ) {}

    /**
     * ダッシュボードの props。各propsをクロージャで返すのは、実行中ランを
     * only=['running'] で2.5秒ごとにポーリングしたときに全社走査・failed_jobs・jobs・
     * バックアップ走査まで再計算させないため（Inertia は要求された props のクロージャだけを呼ぶ）。
     *
     * @return array<string, \Closure>
     */
    public function execute(): array
    {
        return [
            'statusGroups' => fn () => $this->statusGroups($this->companies()),
            // 0件のステータスはカードにせず1行に圧縮する（文言はPHPで確定）
            'emptyGroups' => fn () => $this->emptyGroups($this->companies()),
            // 一括登録した直後の「箱だけ」の企業。次に取り込むべき対象
            'needsSources' => fn () => $this->needsSources($this->companies()),
            // ⏰選考の期限（オファー回答期限・次回面接）。近い/過ぎたものだけを出す
            'deadlines' => fn () => $this->deadlines($this->companies()),
            // ❓面接で確認する論点が未解消のまま残っている企業（面接前に潰す対象）
            'openIssues' => fn () => $this->openIssueCompanies($this->companies()),
            // 📧メールから自動登録したが、まだ目視していない取り込み
            'mailUnreviewed' => fn () => $this->mailUnreviewed(),
            // 📰取り込み済みニュースの新着。ここで外部通信はしない（描画時のRSS取得は禁止）
            'recentNews' => fn () => $this->recentNews($this->companies()),
            // 選考中なのにニュース材料が古い企業（外部通信せずに確認を促す）
            'newsUnchecked' => fn () => $this->newsUnchecked($this->companies()),
            'holds' => fn () => $this->holds($this->companies()),
            'stale' => fn () => $this->staleCompanies($this->companies()),
            // プロファイル更新由来の再分析候補（原因が違うのでソース由来と混ぜない）
            'profileChanged' => fn () => $this->profileChangedCompanies($this->companies()),
            'laborAlerts' => fn () => $this->laborAlerts($this->companies()),
            // 最後に労基公表リストと突合した日時（未実施なら null）
            'laborCheckedAt' => fn () => $this->companies()->max('labor_checked_at')?->format('Y-m-d H:i'),
            // ⏳実行中: 分析の進捗は企業詳細を開かなくてもダッシュボードで分かるようにする
            'running' => fn () => $this->runningRuns($this->companies()),
            // ⛔要対応: 失敗はダッシュボードに必ず出す（企業詳細を開かないと気付けない状態を許さない）
            'failedRuns' => fn () => $this->failedRuns($this->companies()),
            'errorCompanies' => fn () => $this->errorCompanies($this->companies()),
            'failedJobs' => fn () => $this->failedJobs(),
            'queueStalled' => fn () => $this->queueStalledMessage(),
            // バックアップはスケジューラ常駐せず手動運用なので、停滞をトップでも促す
            'backupWarning' => fn () => $this->backupWarning(),
            // 初回導線（企業ゼロ・プロフィール未設定ならオンボーディングを出す）
            'hasProfile' => fn () => filled($this->profile()?->must_json),
            'companyCount' => fn () => $this->companies()->count(),
        ];
    }

    /**
     * 集計対象の全企業。latestRun / latestDoneRun / officialMetrics を先読みし、
     * 社数分の同型クエリ（N+1）を出さない。
     *
     * @return Collection<int, Company>
     */
    private function companies(): Collection
    {
        return $this->companies ??= Company::with([
            'sourceDocuments',
            'officialMetrics',
            'latestRun.steps',
            'latestDoneRun',
            'scheduleEvents',
            // 未解消の論点の件数を出すため。状態で絞らず読み、集計は PHP 側で行う
            'openIssues',
            // 材料の充足度は件数しか見ないので、本文を持たない列だけ読む
            'reviewItems:id,company_id',
        ])->get();
    }

    private function profile(): ?Profile
    {
        if (! $this->profileLoaded) {
            $this->profile = Profile::first();
            $this->profileLoaded = true;
        }

        return $this->profile;
    }

    /**
     * 実行中（待機中・分析中）のラン一覧。ステップ進捗も添えて進み具合を見せる。
     * 最新ランだけを見る（再分析で置き換わった古いランは出さない = failedRuns と同じ扱い）。
     *
     * @param  Collection<int, Company>  $companies
     * @return array<int, array<string, mixed>>
     */
    private function runningRuns(Collection $companies): array
    {
        return $companies
            ->map(function (Company $c) {
                $run = $c->latestRun;

                // queued / running（= 終了していないラン）が対象
                return ($run && ! $run->status->isTerminal()) ? $this->summary($c) + [
                    'runId' => $run->id,
                    'statusLabel' => $run->status->label(),
                    'currentStepLabel' => $run->current_step?->label(),
                    'stepsDone' => $run->steps->where('status', StepStatus::Done)->count(),
                    'stepsTotal' => $this->stepsTotal(),
                    'startedAt' => $run->started_at?->format('Y-m-d H:i'),
                    // 更新が途絶えたランは「応答なし」と出す（中断は企業ページから）
                    'isStuck' => $this->stuck->isStuck($run),
                ] : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /** 自動実行のステップ数。判定エンジン未設定なら RJ は必ずスキップされるので分母に数えない */
    private function stepsTotal(): int
    {
        $total = count(AnalysisStep::pipeline());

        return $this->aiSettings->isDecisionConfigured() ? $total : $total - 1;
    }

    /**
     * 最終バックアップから STALE_DAYS 以上経っている場合の警告文（正常なら null）。
     * 判定は BuildBackupStatusAction（設定画面と同一の基準）を流用する。
     */
    private function backupWarning(): ?string
    {
        $backup = $this->backupStatus->execute();

        if (! $backup['stale']) {
            return null;
        }

        return $backup['latestAt'] === null
            ? 'バックアップがまだ1件もありません（設定画面から手動バックアップを推奨します）'
            : '最終バックアップから'.self::BACKUP_STALE_DAYS."日以上経っています（最終: {$backup['latestAt']}）";
    }

    /**
     * 最新ランが失敗のままの企業（再分析で上書きされた過去の失敗は出さない）。
     *
     * @param  Collection<int, Company>  $companies
     * @return array<int, array<string, mixed>>
     */
    private function failedRuns(Collection $companies): array
    {
        return $companies
            ->map(function (Company $c) {
                $run = $c->latestRun;

                return ($run && $run->status === RunStatus::Failed) ? $this->summary($c) + [
                    'runId' => $run->id,
                    'error' => mb_substr((string) $run->error, 0, self::ERROR_EXCERPT),
                    'failedAt' => $run->finished_at?->format('Y-m-d H:i'),
                ] : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * ブリーフィング生成・EDINET取得が失敗したままの企業。両方失敗なら2行出す。
     *
     * @param  Collection<int, Company>  $companies
     * @return array<int, array<string, mixed>>
     */
    private function errorCompanies(Collection $companies): array
    {
        return $companies
            ->flatMap(fn (Company $c) => collect([
                ['kind' => 'ブリーフィング生成', 'error' => $c->briefing_error],
                ['kind' => 'EDINET取得', 'error' => $c->edinet_error],
            ])
                ->filter(fn (array $e) => $e['error'] !== null)
                ->map(fn (array $e) => $this->summary($c) + [
                    'kind' => $e['kind'],
                    'error' => mb_substr((string) $e['error'], 0, self::ERROR_EXCERPT),
                ]))
            ->values()
            ->all();
    }

    /**
     * キュー実処理で失敗した failed_jobs の行（分析ラン以外の補助ジョブの失敗もここで拾う）。
     *
     * @return array<int, array<string, mixed>>
     */
    private function failedJobs(): array
    {
        return DB::table('failed_jobs')
            ->orderByDesc('id')
            ->get()
            ->map(fn (object $j) => [
                'id' => $j->id,
                'jobName' => (string) json_decode((string) $j->payload, true)['displayName'],
                'excerpt' => mb_substr(explode("\n", (string) $j->exception, 2)[0], 0, self::ERROR_EXCERPT),
                'failedAt' => Carbon::parse($j->failed_at)->format('Y-m-d H:i'),
            ])
            ->all();
    }

    /**
     * 未処理のまま滞留しているジョブがあれば「ワーカー停止の疑い」として文言を確定して返す。
     * queue=database はワーカー未起動でも投入が成功してしまうため、jobs テーブルを観測して知らせる
     * （分析ランに限らず、労基突合・口コミ分割・条件抽出などの補助ジョブの滞留も同じ基準で拾う）。
     */
    private function queueStalledMessage(): ?string
    {
        // jobs.available_at は unix タイムスタンプ
        $count = DB::table('jobs')
            ->where('available_at', '<', now()->subMinutes(self::STALLED_MINUTES)->getTimestamp())
            ->count();

        return $count > 0
            ? "ジョブ{$count}件が".self::STALLED_MINUTES.'分以上未処理です（php artisan queue:work の稼働を確認してください）'
            : null;
    }

    /**
     * 企業が1社もいるステータスだけカードとして返す（0件は emptyGroups に畳む）。
     *
     * @param  Collection<int, Company>  $companies
     * @return array<int, array<string, mixed>>
     */
    private function statusGroups(Collection $companies): array
    {
        return collect(self::STATUS_ORDER)
            ->map(fn (CompanyStatus $status) => [
                'status' => $status->value,
                'label' => $status->label(),
                // 選考が動いている段階か。画面は動いている段階だけ企業名を並べ、気になる・見送り（増える一方）は件数と一覧への導線にする
                'active' => in_array($status, self::ACTIVE_STATUSES, true),
                'companies' => $companies
                    ->filter(fn (Company $c) => $c->status === $status)
                    ->map(fn (Company $c) => $this->summary($c))
                    ->values(),
            ])
            ->filter(fn (array $g) => $g['companies']->isNotEmpty())
            ->values()
            ->all();
    }

    /**
     * 0件ステータスの圧縮表示行（例: 「面接 0 ・ オファー 0」）。全ステータスに企業がいれば null。
     *
     * @param  Collection<int, Company>  $companies
     */
    private function emptyGroups(Collection $companies): ?string
    {
        $line = collect(self::STATUS_ORDER)
            ->filter(fn (CompanyStatus $s) => ! $companies->contains(fn (Company $c) => $c->status === $s))
            ->map(fn (CompanyStatus $s) => $s->label().' 0')
            ->implode(' ・ ');

        return $line === '' ? null : $line;
    }

    /**
     * ソースが1件も無い企業（見送り済みは除く）。分析を始められない状態なので催促する。
     * 並び順は登録順（id昇順）。
     *
     * @param  Collection<int, Company>  $companies
     * @return array<string, mixed>
     */
    private function needsSources(Collection $companies): array
    {
        return $this->alertBlock($companies
            ->filter(fn (Company $c) => $c->sourceDocuments->isEmpty()
                && $c->status !== CompanyStatus::Declined)
            ->map(fn (Company $c) => $this->summary($c)));
    }

    /**
     * 選考の期限アラート（オファー回答期限・次回面接）。
     * 「あと何日 / 今日 / 明日 / 過ぎている」の判定と文言はここで確定する（Vueは描画のみ）。
     * DEADLINE_SOON_DAYS より先の予定は出さない（今日やることを遠い予定で埋めないため）。
     * 並び順は差し迫っている順（過ぎたものが先頭）。
     *
     * @param  Collection<int, Company>  $companies
     * @return array<string, mixed>
     */
    private function deadlines(Collection $companies): array
    {
        $items = $companies
            ->flatMap(fn (Company $c) => [$this->offerDeadlineEntry($c), $this->nextInterviewEntry($c)])
            ->filter()
            ->sortBy('daysLeft');

        return $this->alertBlock($this->withoutKey($items, 'daysLeft'));
    }

    /**
     * オファー回答期限のアラート1件（未設定・まだ先なら null）。
     *
     * @return array<string, mixed>|null
     */
    private function offerDeadlineEntry(Company $c): ?array
    {
        if ($c->offer_deadline === null) {
            return null;
        }

        // copy() でキャストのCarbonインスタンスを壊さない（以降の読み出しに影響させない）
        $days = (int) now()->startOfDay()->diffInDays($c->offer_deadline->copy()->startOfDay(), false);

        if ($days > self::DEADLINE_SOON_DAYS) {
            return null;
        }

        $at = $c->offer_deadline->format('Y-m-d');

        return $this->summary($c) + [
            'kind' => 'offer',
            'overdue' => $days < 0,
            'label' => match (true) {
                $days < 0 => "オファー回答期限を過ぎています（{$at}）",
                $days === 0 => "オファー回答期限は今日（{$at}）",
                $days === 1 => "オファー回答期限は明日（{$at}）",
                default => "オファー回答期限まであと{$days}日（{$at}）",
            },
            'daysLeft' => $days,
        ];
    }

    /**
     * 直近の選考予定のアラート1件（予定なし・まだ先なら null）。
     *
     * 予定は schedule_events に何件でも積めるため、今日以降で最も近い1件だけを出す。
     * 過ぎた予定は履歴として予定表に残るので、ここでは「過ぎています」を出さない。
     *
     * @return array<string, mixed>|null
     */
    private function nextInterviewEntry(Company $c): ?array
    {
        $todayStart = now()->startOfDay();

        $next = $c->scheduleEvents
            ->filter(fn (ScheduleEvent $e) => $e->starts_at >= $todayStart)
            ->sortBy('starts_at')
            ->first();

        if ($next === null) {
            return null;
        }

        $days = (int) $todayStart->diffInDays($next->starts_at->copy()->startOfDay(), false);

        if ($days > self::DEADLINE_SOON_DAYS) {
            return null;
        }

        $at = $next->starts_at->format('Y-m-d H:i');
        $time = $next->starts_at->format('H:i');
        $label = $next->kind->label();

        return $this->summary($c) + [
            'kind' => 'interview',
            'overdue' => false,
            'label' => match (true) {
                $days === 0 => "{$label}は今日 {$time}",
                $days === 1 => "{$label}は明日 {$time}",
                default => "{$label}まであと{$days}日（{$at}）",
            },
            'daysLeft' => $days,
        ];
    }

    /**
     * メールから自動登録したまま未確認の取り込み（新しい順）。
     *
     * メール取り込みは承認ゲートを置かず自動で登録するため、ここに出さないと
     * 「/mails を見に行かない限り誤登録に気づけない」状態になる。
     *
     * @return array<string, mixed>
     */
    private function mailUnreviewed(): array
    {
        $items = MailIngest::query()
            ->with('company')
            ->where('reviewed', false)
            ->recent()
            ->get()
            ->map(fn (MailIngest $m) => [
                'id' => $m->id,
                'subject' => $m->subject,
                'kindLabel' => $m->mail_kind->label(),
                'companyId' => $m->company_id,
                'companyName' => $m->company?->name,
                'receivedAt' => $m->received_at->format('Y-m-d H:i'),
            ]);

        return $this->alertBlock($items);
    }

    /**
     * 直近 NEWS_RECENT_DAYS 日に取り込んだニュースソース（新しい順）。
     *
     * ダッシュボードは外部通信をしない。描画時に RSS を叩くと外部障害でトップが落ちるため、
     * 出せるのは「すでに取り込んだニュース」だけという前提を守る。
     *
     * @param  Collection<int, Company>  $companies
     * @return array<string, mixed>
     */
    private function recentNews(Collection $companies): array
    {
        $since = now()->subDays(self::NEWS_RECENT_DAYS);

        $items = $companies
            ->flatMap(fn (Company $c) => $this->newsDocuments($c)
                ->filter(fn ($s) => $s->created_at?->gte($since) === true)
                ->map(fn ($s) => $this->summary($c) + [
                    'sourceId' => $s->id,
                    // 手貼りのニュースにはタイトルが無いので本文の冒頭を出す（空表示にしない）
                    'title' => $s->title ?? mb_substr((string) $s->raw_text, 0, self::NEWS_EXCERPT),
                    'host' => $s->url === null ? null : (parse_url($s->url, PHP_URL_HOST) ?: null),
                    'ingestedAt' => $s->created_at->format('Y-m-d'),
                ]))
            ->sortByDesc('ingestedAt');

        return $this->alertBlock($items->values());
    }

    /**
     * 選考が動いている企業のうち、ニュース材料が NEWS_CHECK_DAYS 日以上更新されていない企業。
     * 自動巡回はしない方針なので、取り込み済みニュースの最終日時から「確認漏れ」を促す
     * （外部通信は一切しない）。並び順は放置が長い順。
     *
     * @param  Collection<int, Company>  $companies
     * @return array<string, mixed>
     */
    private function newsUnchecked(Collection $companies): array
    {
        $items = $companies
            ->filter(fn (Company $c) => in_array($c->status, self::ACTIVE_STATUSES, true))
            ->map(fn (Company $c) => $this->newsUncheckedEntry($c))
            ->filter()
            ->sortByDesc('daysAgo');

        return $this->alertBlock($this->withoutKey($items, 'daysAgo'));
    }

    /**
     * ニュース確認を促す1件（最近取り込んでいれば null）。
     *
     * @return array<string, mixed>|null
     */
    private function newsUncheckedEntry(Company $c): ?array
    {
        $latest = $this->newsDocuments($c)->max('created_at');
        $days = $latest === null ? null : (int) $latest->copy()->startOfDay()->diffInDays(now()->startOfDay());

        if ($days !== null && $days < self::NEWS_CHECK_DAYS) {
            return null;
        }

        return $this->summary($c) + [
            'statusLabel' => $c->status->label(),
            'label' => $days === null
                ? 'ニュースをまだ1件も取り込んでいません'
                : "ニュースの最終取り込みから{$days}日（{$latest->format('Y-m-d')}）",
            // 未取り込みを最優先で先頭に出す
            'daysAgo' => $days ?? PHP_INT_MAX,
        ];
    }

    /**
     * 企業のニュースソースのみ（sourceDocuments は companies() で先読み済み）。
     *
     * @return Collection<int, SourceDocument>
     */
    private function newsDocuments(Company $c): Collection
    {
        return $c->sourceDocuments->filter(fn ($s) => $s->type === SourceType::News);
    }

    /**
     * 並び替えにだけ使ったキーを落とす（画面で描画しない prop を渡さないため）。
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function withoutKey(Collection $items, string $key): Collection
    {
        return $items->map(fn (array $e) => collect($e)->except($key)->all())->values();
    }

    /**
     * 並び順は登録順（id昇順）。
     *
     * @param  Collection<int, Company>  $companies
     * @return array<string, mixed>
     */
    /**
     * 未解消の論点が残っている企業（件数の多い順）。
     * 「面接までに潰す論点がいくつあるか」を企業を開かずに把握するためのセクション。
     *
     * @param  Collection<int, Company>  $companies
     * @return array<string, mixed>
     */
    private function openIssueCompanies(Collection $companies): array
    {
        $items = $companies
            ->map(fn (Company $c) => [
                'company' => $c,
                'pending' => $c->openIssues->filter(fn (OpenIssue $i) => $i->status->isPending())->count(),
            ])
            ->filter(fn (array $row) => $row['pending'] > 0)
            ->sortByDesc('pending')
            ->map(fn (array $row) => $this->summary($row['company']) + [
                'pendingCount' => $row['pending'],
            ]);

        return $this->alertBlock($items);
    }

    private function holds(Collection $companies): array
    {
        return $this->alertBlock($companies
            ->filter(fn (Company $c) => $c->recommendation === Recommendation::Hold)
            // 「なぜ保留なのか」を企業を開かずに分かるようにする（不足材料の1行）
            ->map(fn (Company $c) => $this->summary($c) + [
                'missing' => $this->coverage->summaryLine($c),
            ]));
    }

    /**
     * 並び順は登録順（id昇順）。
     *
     * @param  Collection<int, Company>  $companies
     * @return array<string, mixed>
     */
    private function laborAlerts(Collection $companies): array
    {
        return $this->alertBlock($companies
            ->filter(fn (Company $c) => $c->labor_note !== null)
            ->map(fn (Company $c) => $this->summary($c) + [
                'note' => mb_substr((string) $c->labor_note, 0, self::NOTE_EXCERPT),
            ]));
    }

    /**
     * 最終分析（done）が見た材料から変わっている企業 = 再分析候補。
     * 並び順は変わった材料が多い順。
     *
     * @param  Collection<int, Company>  $companies
     * @return array<string, mixed>
     */
    private function staleCompanies(Collection $companies): array
    {
        return $this->alertBlock($companies
            ->map(fn (Company $c) => $this->staleEntry($c))
            ->filter()
            ->sortByDesc(fn (array $e) => $e['newSources'] + $e['newMetrics']
                + $e['removedSources'] + $e['removedMetrics']));
    }

    /**
     * 最終分析から変わった材料の件数つきの要約。再分析不要なら null。
     * ソース（求人票・口コミ等）由来と公的数値（EDINET/しょくばらぼ等）由来は
     * 次にやることが変わるため件数を分けて返す。
     * 判定はカルテ画面と同じ MaterialFreshness（画面ごとに結論が食い違わないようにする）。
     *
     * @return array<string, mixed>|null
     */
    private function staleEntry(Company $c): ?array
    {
        $lastDone = $c->latestDoneRun;

        if ($lastDone === null) {
            return null;
        }

        $freshness = $this->freshness->compare($c, $lastDone);

        return $freshness['isStale']
            ? $this->summary($c) + [
                'newSources' => $freshness['newSources'],
                'newMetrics' => $freshness['newMetrics'],
                'removedSources' => $freshness['removedSources'],
                'removedMetrics' => $freshness['removedMetrics'],
            ]
            : null;
    }

    /**
     * プロファイル（キャリア要約・must/want/NG・軸）を更新した後に再分析していない企業。
     * 材料が増えたわけではなく「評価の前提が変わった」ケースなので stale とは別ブロックにする。
     *
     * 判定は analysis_relevant_updated_at（分析に効く項目が実際に変わった時刻。
     * 更新は ProfileController が確定する）。updated_at を見ると現年収だけの更新で
     * 全社が候補に挙がって誤報になるため使わない。一度も分析関連項目を保存していない
     * プロファイルは基準時刻を持たないので候補を出さない。
     *
     * @param  Collection<int, Company>  $companies
     * @return array<string, mixed>
     */
    private function profileChangedCompanies(Collection $companies): array
    {
        $changedAt = $this->profile()?->analysis_relevant_updated_at;

        if ($changedAt === null) {
            return $this->alertBlock(collect());
        }

        return $this->alertBlock($companies
            ->filter(fn (Company $c) => $c->latestDoneRun?->finished_at?->lt($changedAt) === true)
            ->map(fn (Company $c) => $this->summary($c)));
    }

    /**
     * アラート1ブロックを「上位N件 + 総数 + 残数」の表示形に確定する（切り出しはPHP側。Vueは描画のみ）。
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function alertBlock(Collection $items): array
    {
        return [
            'items' => $items->take(self::ALERT_ITEM_LIMIT)->values()->all(),
            'total' => $items->count(),
            'more' => max(0, $items->count() - self::ALERT_ITEM_LIMIT),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Company $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'recommendationSymbol' => $c->recommendation?->symbol(),
            // 面接前ブリーフィング生成済みか（🎤マーカー用）
            'hasBriefing' => $c->briefing_generated_at !== null,
            // ★お気に入りか（選考ステータス別の上に出すお気に入り欄はこの値で選ぶ）
            'favorite' => $c->favorite,
        ];
    }
}
