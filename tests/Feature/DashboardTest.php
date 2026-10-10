<?php

namespace Tests\Feature;

use App\Enums\AnalysisStep;
use App\Enums\CompanyStatus;
use App\Enums\MetricSource;
use App\Enums\Recommendation;
use App\Enums\RunStatus;
use App\Enums\ScheduleKind;
use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Enums\StepStatus;
use App\Models\Company;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * バックアップ判定を実データ（storage/backups）から切り離す。
     * storage パス自体をテスト専用ディレクトリに差し替えるので、実ファイルは一切消さない。
     * 戻り値は差し替え後の backups ディレクトリ（作成済み・空）。
     */
    private function useTempBackupDir(): string
    {
        $root = storage_path('framework/testing/dashboard-'.Str::random(8));
        $backups = $root.'/backups';
        File::ensureDirectoryExists($backups);
        $this->app->useStoragePath($root);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));

        return $backups;
    }

    /** jobs テーブルに未処理ジョブを1件入れる（available_at は unix タイムスタンプ） */
    private function insertPendingJob(int $minutesAgo): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\LaborCheckJob']),
            'attempts' => 0,
            'available_at' => now()->subMinutes($minutesAgo)->getTimestamp(),
            'created_at' => now()->subMinutes($minutesAgo)->getTimestamp(),
        ]);
    }

    /** failed_jobs に失敗ジョブを1件入れて id を返す */
    private function insertFailedJob(): int
    {
        return (int) DB::table('failed_jobs')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\LaborCheckJob']),
            'exception' => "RuntimeException: 労基PDFの取得に失敗しました (404)\n#0 /app/Jobs/LaborCheckJob.php(42)",
            'failed_at' => now(),
        ]);
    }

    public function test_ダッシュボードが表示されステータス別に並ぶ(): void
    {
        Company::create(['name' => '面接社', 'status' => CompanyStatus::Interview]);
        Company::create(['name' => '気になる社']);

        // 0件のステータスはカードにならない（emptyGroups に圧縮される）
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->has('statusGroups', 2)
                ->where('statusGroups.0.status', 'interview')
                ->where('statusGroups.0.companies.0.name', '面接社'));
    }

    public function test_空のステータスは1行に圧縮される(): void
    {
        Company::create(['name' => '面接社', 'status' => CompanyStatus::Interview]);
        Company::create(['name' => '気になる社']);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('emptyGroups', 'オファー 0 ・ 応募 0 ・ カジュアル面談 0 ・ 見送り 0'));
    }

    public function test_ステータス別の企業に★お気に入りかが付く(): void
    {
        Company::create(['name' => '★社', 'favorite' => true]);
        Company::create(['name' => '普通社']);

        // お気に入り欄は選考ステータス別と同じデータから作るので、ここに★が載っていれば足りる
        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('statusGroups.0.companies', fn ($companies) => $companies->firstWhere('name', '★社')['favorite'] === true
                    && $companies->firstWhere('name', '普通社')['favorite'] === false));
    }

    public function test_選考が動いている段階だけ企業名を並べる印が付く(): void
    {
        Company::create(['name' => '面接社', 'status' => CompanyStatus::Interview]);
        Company::create(['name' => '気になる社', 'status' => CompanyStatus::Kininaru]);
        Company::create(['name' => '見送り社', 'status' => CompanyStatus::Declined]);

        // 並びは動いている選考が先（面接 → 気になる → 見送り）
        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('statusGroups.0.status', 'interview')
                ->where('statusGroups.0.active', true)
                ->where('statusGroups.1.status', 'kininaru')
                ->where('statusGroups.1.active', false)
                ->where('statusGroups.2.status', 'declined')
                ->where('statusGroups.2.active', false));
    }

    public function test_ソース未投入の企業が催促リストに出る(): void
    {
        $withSource = Company::create(['name' => 'ソースあり社']);
        $withSource->sourceDocuments()->create([
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '求人票',
        ]);
        Company::create(['name' => 'ソースなし社']);
        Company::create(['name' => '見送り社', 'status' => CompanyStatus::Declined]);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('needsSources.items', 1)
                ->where('needsSources.items.0.name', 'ソースなし社')
                ->where('needsSources.total', 1));
    }

    public function test_アラートは上位8件に切り出され残数が付く(): void
    {
        foreach (range(1, 10) as $i) {
            Company::create(['name' => "未投入{$i}"]);
        }

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('needsSources.items', 8)
                ->where('needsSources.total', 10)
                ->where('needsSources.more', 2));
    }

    public function test_判定保留の企業がアラートに出る(): void
    {
        Company::create(['name' => '保留社', 'recommendation' => Recommendation::Hold]);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('holds.items.0.name', '保留社'));
    }

    public function test_分析後にソースが増えた企業は再分析候補に出る(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $company->analysisRuns()->create([
            'status' => RunStatus::Done,
            'finished_at' => now()->subDay(),
        ]);
        $company->sourceDocuments()->create([
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '分析後に増えた求人票',
        ]);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('stale.items.0.name', '株式会社テスト')
                ->where('stale.items.0.newSources', 1)
                ->where('stale.items.0.newMetrics', 0));
    }

    public function test_分析後に公的数値が増えた企業も再分析候補に出る(): void
    {
        $company = Company::create(['name' => '公的数値追加社']);
        $company->analysisRuns()->create([
            'status' => RunStatus::Done,
            'finished_at' => now()->subDay(),
        ]);
        $company->officialMetrics()->create([
            'source' => MetricSource::Shokuba,
            'key' => '平均残業時間',
            'value' => '28',
            'unit' => '時間/月',
        ]);

        // ソース由来と公的数値由来が区別できること（次にやることが変わる）
        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stale.items.0.name', '公的数値追加社')
                ->where('stale.items.0.newSources', 0)
                ->where('stale.items.0.newMetrics', 1));
    }

    public function test_分析後に材料が増えていなければ再分析候補に出ない(): void
    {
        $company = Company::create(['name' => '据え置き社']);
        $company->sourceDocuments()->create([
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '分析前に入れた求人票',
        ]);
        $company->officialMetrics()->create([
            'source' => MetricSource::Shokuba,
            'key' => '平均残業時間',
            'value' => '28',
            'unit' => '時間/月',
        ]);
        // 材料より後に分析が完了している状態
        $company->analysisRuns()->create(['status' => RunStatus::Done, 'finished_at' => now()->addMinute()]);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stale.total', 0)
                ->where('profileChanged.total', 0));
    }

    /**
     * 設定画面と同じ形のプロファイル保存（Settings.vue は全項目を毎回送る）。
     * 再分析候補の基準時刻 analysis_relevant_updated_at はこの経路でのみ進む。
     *
     * @param  array<string, mixed>  $override
     */
    private function putProfile(array $override = []): void
    {
        $this->put('/settings/profile', array_merge([
            'career_summary' => 'Webアプリ開発の経験',
            'must_json' => ['リモート可'],
            'want_json' => [],
            'ng_json' => [],
            'axis' => '長く働ける環境',
            'current_annual_salary' => 450,
        ], $override))->assertRedirect();
    }

    public function test_プロファイル更新後に再分析していない企業は別ブロックに出る(): void
    {
        $company = Company::create(['name' => 'プロファイル後社']);
        $company->analysisRuns()->create([
            'status' => RunStatus::Done,
            'finished_at' => now()->subDay(),
        ]);
        $this->putProfile();

        // ソースは増えていないので stale ではなく profileChanged に出る
        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stale.total', 0)
                ->where('profileChanged.total', 1)
                ->where('profileChanged.items.0.name', 'プロファイル後社'));
    }

    public function test_プロファイル更新より後に分析済みなら別ブロックに出ない(): void
    {
        $this->putProfile();
        $company = Company::create(['name' => '分析済み社']);
        $company->analysisRuns()->create([
            'status' => RunStatus::Done,
            'finished_at' => now()->addMinute(),
        ]);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('profileChanged.total', 0));
    }

    public function test_現年収だけのプロファイル更新では再分析候補に出ない(): void
    {
        $this->putProfile();
        $company = Company::create(['name' => '年収だけ変えた社']);
        $company->analysisRuns()->create([
            'status' => RunStatus::Done,
            'finished_at' => now()->addMinute(),
        ]);

        // 現年収は codex に渡さない項目なので、変えても評価の前提は変わらない
        $this->travelTo(now()->addMinutes(5), fn () => $this->putProfile(['current_annual_salary' => 700]));

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('profileChanged.total', 0));
        $this->assertSame(700, Profile::first()->current_annual_salary);
    }

    public function test_必須条件の変更では再分析候補に出る(): void
    {
        $this->putProfile();
        $company = Company::create(['name' => '必須条件を変えた社']);
        $company->analysisRuns()->create([
            'status' => RunStatus::Done,
            'finished_at' => now()->addMinute(),
        ]);

        $this->travelTo(now()->addMinutes(5), fn () => $this->putProfile(['must_json' => ['フルリモート必須']]));

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('profileChanged.total', 1)
                ->where('profileChanged.items.0.name', '必須条件を変えた社'));
    }

    public function test_分析関連項目を一度も保存していなければ再分析候補に出ない(): void
    {
        // モデル直作成（= 基準時刻なし）。判定基準が無いものを「変わった」とみなさない
        Profile::create(['career_summary' => 'Webアプリ開発の経験', 'must_json' => ['リモート可']]);
        $company = Company::create(['name' => '基準なし社']);
        $company->analysisRuns()->create([
            'status' => RunStatus::Done,
            'finished_at' => now()->subDay(),
        ]);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('profileChanged.total', 0));
    }

    public function test_労基公表リスト一致の企業はアラートに出る(): void
    {
        Company::create(['name' => '一致社', 'labor_note' => '2026-06 公表事案に社名一致: 労働基準法違反']);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('laborAlerts.items.0.name', '一致社'));
    }

    public function test_最新ランが失敗の企業は要対応に出る(): void
    {
        $company = Company::create(['name' => '失敗社']);
        $company->analysisRuns()->create([
            'status' => RunStatus::Failed,
            'error' => 'codex がタイムアウトしました',
            'finished_at' => now(),
        ]);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('failedRuns.0.name', '失敗社')
                ->where('failedRuns.0.error', 'codex がタイムアウトしました'));
    }

    public function test_再分析で成功した企業は要対応に残らない(): void
    {
        $company = Company::create(['name' => '復活社']);
        $company->analysisRuns()->create(['status' => RunStatus::Failed, 'error' => '一時的な失敗']);
        $company->analysisRuns()->create(['status' => RunStatus::Done, 'finished_at' => now()]);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('failedRuns', 0));
    }

    public function test_実行中の分析が進捗つきで実行中ブロックに出る(): void
    {
        $company = Company::create(['name' => '実行中社']);
        $run = $company->analysisRuns()->create([
            'status' => RunStatus::Running,
            'current_step' => AnalysisStep::P2,
            'started_at' => now(),
        ]);
        $run->steps()->create(['step' => AnalysisStep::P1, 'status' => StepStatus::Done]);
        $run->steps()->create(['step' => AnalysisStep::P2, 'status' => StepStatus::Running]);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('running', 1)
                ->where('running.0.name', '実行中社')
                ->where('running.0.statusLabel', '分析中')
                ->where('running.0.currentStepLabel', '裏取り判定')
                ->where('running.0.stepsDone', 1)
                // 逆質問(P6)は分析に含めず個別生成なので P1〜P5 の 5 ステップ。
                // 口コミの機械判定（RJ）は判定エンジン未設定なら必ずスキップされるので分母に数えない
                ->where('running.0.stepsTotal', 5));
    }

    public function test_待機中の分析も実行中ブロックに出る(): void
    {
        $company = Company::create(['name' => '待機中社']);
        $company->analysisRuns()->create(['status' => RunStatus::Queued]);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('running', 1)
                ->where('running.0.statusLabel', '待機中')
                ->where('running.0.currentStepLabel', null)
                ->where('running.0.stepsDone', 0));
    }

    public function test_完了と失敗のランは実行中ブロックに出ない(): void
    {
        Company::create(['name' => '完了社'])->analysisRuns()->create([
            'status' => RunStatus::Done,
            'finished_at' => now(),
        ]);
        Company::create(['name' => '失敗社'])->analysisRuns()->create([
            'status' => RunStatus::Failed,
            'error' => '失敗',
            'finished_at' => now(),
        ]);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('running', 0));
    }

    public function test_バックアップ未実施なら警告ブロックが出る(): void
    {
        // backups ディレクトリが空（= 1件も無い）状態
        $this->useTempBackupDir();

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('backupWarning', 'バックアップがまだ1件もありません（設定画面から手動バックアップを推奨します）'));
    }

    public function test_直近にバックアップがあれば警告は出ない(): void
    {
        $dir = $this->useTempBackupDir();
        File::put($dir.'/database-'.now()->format('Ymd-His').'.sqlite', 'dummy');

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('backupWarning', null));
    }

    public function test_ブリーフィング失敗も要対応に出る(): void
    {
        Company::create(['name' => 'ブリ失敗社', 'briefing_error' => '生成に失敗しました']);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('errorCompanies.0.name', 'ブリ失敗社')
                ->where('errorCompanies.0.kind', 'ブリーフィング生成'));
    }

    public function test_ブリーフィングと有報取得の両方失敗なら2行出る(): void
    {
        Company::create([
            'name' => '両方失敗社',
            'briefing_error' => '生成に失敗しました',
            'edinet_error' => '書類取得に失敗しました',
        ]);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('errorCompanies', 2)
                ->where('errorCompanies.0.kind', 'ブリーフィング生成')
                ->where('errorCompanies.1.kind', 'EDINET取得'));
    }

    public function test_キューで失敗したジョブが要対応に出る(): void
    {
        $this->insertFailedJob();

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('failedJobs', 1)
                ->where('failedJobs.0.jobName', 'App\\Jobs\\LaborCheckJob')
                // 例外は1行目だけを抜粋する（スタックトレースは出さない）
                ->where('failedJobs.0.excerpt', 'RuntimeException: 労基PDFの取得に失敗しました (404)'));
    }

    public function test_失敗ジョブを削除できる(): void
    {
        $id = $this->insertFailedJob();

        $this->delete("/failed-jobs/{$id}")->assertRedirect();

        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_10分以上未処理のジョブはワーカー停止の疑いとして出る(): void
    {
        // 労基突合など analysis_runs を作らない補助ジョブの滞留も jobs テーブル基準で拾う
        $this->insertPendingJob(minutesAgo: 15);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('queueStalled', 'ジョブ1件が10分以上未処理です（php artisan queue:work の稼働を確認してください）'));
    }

    public function test_投入直後のジョブは滞留として出ない(): void
    {
        $this->insertPendingJob(minutesAgo: 0);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('queueStalled', null));
    }

    public function test_古い失敗ランを再開しても滞留の誤警告は出ない(): void
    {
        Queue::fake();
        // 再開はバックアップ必須の経路を通る。本体の VACUUM INTO はトランザクション内で動かないため差し替える
        Artisan::command('karte:backup {--keep=1}', fn () => 0);
        $company = Company::create(['name' => '再開社']);
        $run = $company->analysisRuns()->create(['status' => RunStatus::Failed, 'error' => '失敗']);
        $run->timestamps = false;
        $run->forceFill(['created_at' => now()->subMinutes(30)])->save();

        $this->post("/companies/{$company->id}/runs/{$run->id}/retry")->assertRedirect();

        // 旧実装（analysis_runs.created_at 基準）では再開直後に即誤警告が出ていた
        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('queueStalled', null)
                ->has('failedRuns', 0));
    }

    public function test_ダッシュボード1表示のクエリ数は社数に比例しない(): void
    {
        foreach (range(1, 23) as $i) {
            $company = Company::create(['name' => "企業{$i}"]);
            $company->analysisRuns()->create([
                'status' => RunStatus::Done,
                'finished_at' => now()->subDay(),
            ]);
            $company->analysisRuns()->create(['status' => RunStatus::Failed, 'error' => '失敗']);
            $company->sourceDocuments()->create([
                'type' => SourceType::JobPosting,
                'origin' => SourceOrigin::Paste,
                'raw_text' => '分析後に増えた求人票',
            ]);
        }

        DB::enableQueryLog();
        $this->get('/')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // latestRun/latestRun.steps/latestDoneRun/officialMetrics の先読みで
        // 社数分の同型クエリ（N+1）を出さない（先読み追加ぶんの固定コストは +2 件）。
        // 未確認メール取り込みのアラートで固定 +1 件（社数に比例しない単独クエリ）。
        // 論点（未解消件数）と口コミアイテム（材料の充足度）の先読みで固定 +2 件
        $this->assertLessThanOrEqual(15, $queries, "ダッシュボード1表示で{$queries}クエリ発行された");
    }

    public function test_実行中ブロックだけの部分リロードでは全体集計を評価しない(): void
    {
        $company = Company::create(['name' => '実行中社']);
        $company->analysisRuns()->create(['status' => RunStatus::Running, 'started_at' => now()]);
        $this->insertFailedJob();

        DB::enableQueryLog();
        $response = $this->get('/')->assertOk();
        $fullCount = count(DB::getQueryLog());

        // 2.5秒ごとのポーリング（router.reload({ only: ['running'] })）と同じ部分リクエスト
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->reloadOnly('running', fn (AssertableInertia $polled) => $polled
                ->has('running', 1)
                ->missing('failedRuns')
                ->missing('stale')));

        $polledQueries = collect(DB::getQueryLog())->skip($fullCount)->pluck('query')->implode(' ');
        DB::disableQueryLog();

        // props をクロージャで返しているため、全体集計（failed_jobs / jobs 走査）は評価されない
        $this->assertStringNotContainsString('failed_jobs', $polledQueries);
        $this->assertStringNotContainsString('from "jobs"', $polledQueries);
    }

    public function test_面接オファーの企業にはブリーフィング生成済みフラグが載る(): void
    {
        Company::create(['name' => '生成済み社', 'status' => CompanyStatus::Interview, 'briefing_generated_at' => now()]);
        Company::create(['name' => '未生成社', 'status' => CompanyStatus::Interview]);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('statusGroups.0.companies.0.hasBriefing', true)
                ->where('statusGroups.0.companies.1.hasBriefing', false));
    }

    public function test_企業ゼロならオンボーディング用の件数が渡る(): void
    {
        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('companyCount', 0)
                ->where('hasProfile', false)
                ->where('queueStalled', null));
    }

    public function test_オファー回答期限が近い企業はアラートに出る(): void
    {
        Company::create([
            'name' => '期限間近社',
            'status' => CompanyStatus::Offer,
            'offer_deadline' => now()->addDays(3)->format('Y-m-d'),
        ]);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('deadlines.total', 1)
                ->where('deadlines.items.0.name', '期限間近社')
                ->where('deadlines.items.0.kind', 'offer')
                ->where('deadlines.items.0.overdue', false)
                ->where('deadlines.items.0.label', 'オファー回答期限まであと3日（'.now()->addDays(3)->format('Y-m-d').'）'));
    }

    public function test_オファー回答期限を過ぎた企業は過ぎた表示になる(): void
    {
        Company::create([
            'name' => '期限切れ社',
            'status' => CompanyStatus::Offer,
            'offer_deadline' => now()->subDay()->format('Y-m-d'),
        ]);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('deadlines.items.0.overdue', true)
                ->where('deadlines.items.0.label', 'オファー回答期限を過ぎています（'.now()->subDay()->format('Y-m-d').'）'));
    }

    public function test_遠い期限はアラートに出さない(): void
    {
        Company::create([
            'name' => '来月回答社',
            'status' => CompanyStatus::Offer,
            'offer_deadline' => now()->addDays(30)->format('Y-m-d'),
        ]);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('deadlines.total', 0));
    }

    public function test_次回面接が明日ならアラートに出る(): void
    {
        $company = Company::create([
            'name' => '明日面接社',
            'status' => CompanyStatus::Interview,
        ]);
        $company->scheduleEvents()->create([
            'kind' => ScheduleKind::Interview,
            'starts_at' => now()->addDay()->setTime(10, 30),
        ]);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('deadlines.total', 1)
                ->where('deadlines.items.0.kind', 'interview')
                ->where('deadlines.items.0.label', '面接は明日 10:30'));
    }

    public function test_予定が複数あっても直近の1件だけがアラートに出る(): void
    {
        $company = Company::create(['name' => '複数予定社', 'status' => CompanyStatus::Interview]);
        $company->scheduleEvents()->create([
            'kind' => ScheduleKind::Interview,
            'title' => '2次',
            'starts_at' => now()->addDays(3)->setTime(15, 0),
        ]);
        $company->scheduleEvents()->create([
            'kind' => ScheduleKind::Interview,
            'title' => '1次',
            'starts_at' => now()->addDay()->setTime(10, 30),
        ]);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('deadlines.total', 1)
                ->where('deadlines.items.0.label', '面接は明日 10:30'));
    }

    public function test_過ぎた予定はアラートに出ない(): void
    {
        $company = Company::create(['name' => '面接済社', 'status' => CompanyStatus::Interview]);
        $company->scheduleEvents()->create([
            'kind' => ScheduleKind::Interview,
            'starts_at' => now()->subDays(2)->setTime(10, 0),
        ]);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('deadlines.total', 0));
    }

    public function test_期限は差し迫っている順に並ぶ(): void
    {
        Company::create([
            'name' => '5日後社',
            'status' => CompanyStatus::Offer,
            'offer_deadline' => now()->addDays(5)->format('Y-m-d'),
        ]);
        Company::create(['name' => '今日面接社', 'status' => CompanyStatus::Interview])
            ->scheduleEvents()->create([
                'kind' => ScheduleKind::Interview,
                'starts_at' => now()->setTime(9, 0),
            ]);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('deadlines.total', 2)
                ->where('deadlines.items.0.name', '今日面接社')
                ->where('deadlines.items.1.name', '5日後社'));
    }

    public function test_最近取り込んだニュースがダッシュボードに出る(): void
    {
        $company = Company::create(['name' => 'ニュース社', 'status' => CompanyStatus::Interview]);
        $company->sourceDocuments()->create([
            'type' => SourceType::News,
            'origin' => SourceOrigin::Url,
            'url' => 'https://example-news.jp/articles/1',
            'title' => 'ニュース社が新工場を建設',
            'raw_text' => '記事本文',
        ]);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('recentNews.total', 1)
                ->where('recentNews.items.0.title', 'ニュース社が新工場を建設')
                ->where('recentNews.items.0.host', 'example-news.jp')
                ->where('recentNews.items.0.ingestedAt', now()->format('Y-m-d')));
    }

    public function test_古いニュースは新着ブロックに出さない(): void
    {
        $company = Company::create(['name' => '古ニュース社', 'status' => CompanyStatus::Interview]);
        $source = $company->sourceDocuments()->create([
            'type' => SourceType::News,
            'origin' => SourceOrigin::Url,
            'title' => '半年前の記事',
            'raw_text' => '記事本文',
        ]);
        $source->forceFill(['created_at' => now()->subMonths(6)])->save();

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('recentNews.total', 0));
    }

    public function test_選考中でニュース未取り込みの企業は確認を促す(): void
    {
        Company::create(['name' => '面接中社', 'status' => CompanyStatus::Interview]);
        // 気になる（選考が動いていない）企業は促さない
        Company::create(['name' => '気になる社', 'status' => CompanyStatus::Kininaru]);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('newsUnchecked.total', 1)
                ->where('newsUnchecked.items.0.name', '面接中社')
                ->where('newsUnchecked.items.0.statusLabel', '面接')
                ->where('newsUnchecked.items.0.label', 'ニュースをまだ1件も取り込んでいません'));
    }

    public function test_直近にニュースを取り込んだ企業は確認を促さない(): void
    {
        $company = Company::create(['name' => '確認済み社', 'status' => CompanyStatus::Interview]);
        $company->sourceDocuments()->create([
            'type' => SourceType::News,
            'origin' => SourceOrigin::Url,
            'title' => '今日取り込んだ記事',
            'raw_text' => '記事本文',
        ]);

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('newsUnchecked.total', 0));
    }

    public function test_ニュースの取り込みが古い選考中企業は日数つきで促す(): void
    {
        $company = Company::create(['name' => '放置社', 'status' => CompanyStatus::Applied]);
        $source = $company->sourceDocuments()->create([
            'type' => SourceType::News,
            'origin' => SourceOrigin::Url,
            'title' => '10日前の記事',
            'raw_text' => '記事本文',
        ]);
        $source->forceFill(['created_at' => now()->subDays(10)])->save();

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('newsUnchecked.total', 1)
                ->where('newsUnchecked.items.0.label', 'ニュースの最終取り込みから10日（'.now()->subDays(10)->format('Y-m-d').'）'));
    }
}
