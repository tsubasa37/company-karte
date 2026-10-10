<?php

namespace Tests\Feature;

use App\Actions\RunAnalysisAction;
use App\Enums\RunStatus;
use App\Enums\StepStatus;
use App\Exceptions\AnalysisBlockedException;
use App\Jobs\AnalyzeCompanyJob;
use App\Jobs\GenerateBriefingJob;
use App\Models\AnalysisRun;
use App\Models\Company;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AnalysisGuardTest extends TestCase
{
    use RefreshDatabase;

    private function companyWithSource(): Company
    {
        $company = Company::create(['name' => 'テスト社']);
        $company->sourceDocuments()->create([
            'type' => 'jobposting',
            'origin' => 'paste',
            'raw_text' => '求人票の本文',
        ]);

        return $company;
    }

    public function test_実行中のランがあれば新規分析を弾く(): void
    {
        Queue::fake();
        $company = $this->companyWithSource();
        $company->analysisRuns()->create(['status' => RunStatus::Running]);

        $this->post("/companies/{$company->id}/analyze", ['panel_size' => 1])
            ->assertSessionHasErrors('analyze');

        Queue::assertNothingPushed();
    }

    /**
     * 分析前バックアップ（karte:backup）を差し替える。
     * 本体は VACUUM INTO を使い、RefreshDatabase のトランザクション内では実行できないため。
     * バックアップ自体の検証は AnalysisBackupTest / BackupCommandTest が持つ。
     */
    private function fakeBackupCommand(): void
    {
        Artisan::command('karte:backup {--keep=7}', fn () => 0);
    }

    public function test_新規分析が待機中なら古い失敗ランを再開できない(): void
    {
        Queue::fake();
        $this->fakeBackupCommand();
        Profile::create(['must_json' => ['年収400万以上']]);
        $company = $this->companyWithSource();

        // 過去に失敗したラン
        $failed = $company->analysisRuns()->create(['status' => RunStatus::Failed, 'error' => 'timeout']);
        $failed->steps()->create(['step' => 'p2', 'status' => StepStatus::Failed, 'error' => 'timeout']);
        // 新しい分析を投入（失敗ランはガードに掛からないので通る）
        $this->post("/companies/{$company->id}/analyze", ['panel_size' => 1])
            ->assertSessionDoesntHaveErrors();

        // 待機中の別ランがあるのに古い失敗ランを再開すると AI を二重に消費する
        $this->post("/companies/{$company->id}/runs/{$failed->id}/retry")
            ->assertSessionHasErrors('analyze');

        $this->assertSame(RunStatus::Failed, $failed->fresh()->status);
        $this->assertSame(1, $company->analysisRuns()->unfinished()->count());
        Queue::assertPushed(AnalyzeCompanyJob::class, 1);
    }

    public function test_他に未完了ランが無ければ失敗ランを再開できる(): void
    {
        Queue::fake();
        $this->fakeBackupCommand();
        $company = $this->companyWithSource();
        $failed = $company->analysisRuns()->create(['status' => RunStatus::Failed, 'error' => 'timeout']);
        $failed->steps()->create(['step' => 'p2', 'status' => StepStatus::Failed, 'error' => 'timeout']);

        $this->post("/companies/{$company->id}/runs/{$failed->id}/retry")
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(RunStatus::Queued, $failed->fresh()->status);
        Queue::assertPushed(AnalyzeCompanyJob::class, 1);
    }

    public function test_ソースとプロファイルがあり実行中でなければ分析を積む(): void
    {
        Queue::fake();
        $this->fakeBackupCommand();
        Profile::create(['must_json' => ['年収400万以上']]);
        $company = $this->companyWithSource();

        $this->post("/companies/{$company->id}/analyze", ['panel_size' => 1]);

        Queue::assertPushed(AnalyzeCompanyJob::class);
    }

    public function test_プロファイル未設定なら分析を弾く(): void
    {
        Queue::fake();
        $company = $this->companyWithSource();

        $this->post("/companies/{$company->id}/analyze", ['panel_size' => 1])
            ->assertSessionHasErrors('analyze');

        Queue::assertNothingPushed();
    }

    public function test_失敗ランを続きから再開できる(): void
    {
        Queue::fake();
        // 再開も分析実行なので、新規実行と同じくバックアップ必須の経路を通る
        $this->fakeBackupCommand();
        $company = $this->companyWithSource();
        $run = $company->analysisRuns()->create(['status' => RunStatus::Failed]);
        $run->steps()->create(['step' => 'p1', 'status' => StepStatus::Done]);
        $run->steps()->create(['step' => 'p2', 'status' => StepStatus::Failed]);

        $this->post("/companies/{$company->id}/runs/{$run->id}/retry");

        Queue::assertPushed(AnalyzeCompanyJob::class);
        // 失敗ステップは pending に戻り、完了済みは維持される（続きから再開）
        $this->assertSame(RunStatus::Queued, $run->fresh()->status);
        $this->assertSame(StepStatus::Pending, $run->steps()->firstWhere('step', 'p2')->status);
        $this->assertSame(StepStatus::Done, $run->steps()->firstWhere('step', 'p1')->status);
    }

    public function test_再開でも分析前バックアップを必ず通る(): void
    {
        // 再開だけバックアップを素通りさせない（分析は recommendation を上書きする破壊的操作）
        Queue::fake();
        $called = 0;
        Artisan::command('karte:backup {--keep=1}', function () use (&$called) {
            $called++;

            return 0;
        });
        $company = $this->companyWithSource();
        $run = $company->analysisRuns()->create(['status' => RunStatus::Failed]);

        $this->post("/companies/{$company->id}/runs/{$run->id}/retry");

        $this->assertSame(1, $called, '再開時にもバックアップコマンドが1回呼ばれる');
        Queue::assertPushed(AnalyzeCompanyJob::class);
    }

    /** ランとステップの最終更新を $seconds 秒前に固定する（timestamps は自動更新されるため直接書く） */
    private function age(AnalysisRun $run, int $seconds): void
    {
        $at = now()->subSeconds($seconds);
        DB::table($run->getTable())->where('id', $run->id)->update(['updated_at' => $at, 'started_at' => $at]);
        $run->steps()->update(['updated_at' => $at]);
    }

    public function test_応答が途絶えたランを中断して失敗扱いにできる(): void
    {
        config(['queue.connections.database.retry_after' => 600]);
        $company = $this->companyWithSource();
        $run = $company->analysisRuns()->create(['status' => RunStatus::Running]);
        $run->steps()->create(['step' => 'p1', 'status' => StepStatus::Done]);
        $running = $run->steps()->create(['step' => 'p2', 'status' => StepStatus::Running]);
        $this->age($run, 3600);

        $this->post("/companies/{$company->id}/runs/{$run->id}/abort")
            ->assertRedirect(route('companies.show', $company))
            ->assertSessionDoesntHaveErrors();

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('中断', (string) $run->error);
        $this->assertSame(StepStatus::Failed, $running->fresh()->status, '実行中だったステップは失敗に確定する');
        $this->assertSame(StepStatus::Done, $run->steps()->firstWhere('step', 'p1')->status, '完了済みは残す');
    }

    public function test_まだ動いているランは中断できない(): void
    {
        config(['queue.connections.database.retry_after' => 600]);
        $company = $this->companyWithSource();
        $run = $company->analysisRuns()->create(['status' => RunStatus::Running, 'started_at' => now()]);

        $this->post("/companies/{$company->id}/runs/{$run->id}/abort")->assertSessionHasErrors('analyze');

        $this->assertSame(RunStatus::Running, $run->fresh()->status);
    }

    public function test_中断するとキューに残った同じランのジョブ行も消える(): void
    {
        // database ドライバで実際に jobs テーブルへ積む（sync だと行が残らない）。
        // 残すとワーカー再起動後に再試行され、再開後のランを失敗に上書きするため消す
        config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 3600]);
        $company = $this->companyWithSource();
        $run = $company->analysisRuns()->create(['status' => RunStatus::Queued]);
        $other = $company->analysisRuns()->create(['status' => RunStatus::Queued]);
        AnalyzeCompanyJob::dispatch($run->id);
        AnalyzeCompanyJob::dispatch($other->id);
        $this->assertSame(2, DB::table('jobs')->count());
        $this->age($run, 3601);

        $this->post("/companies/{$company->id}/runs/{$run->id}/abort")->assertSessionDoesntHaveErrors();

        $this->assertSame(1, DB::table('jobs')->count(), '中断したランのジョブ行だけ消える');
        $this->assertSame(RunStatus::Failed, $run->fresh()->status);
        $this->assertSame(RunStatus::Queued, $other->fresh()->status);
    }

    public function test_応答が途絶えたランは企業ページで中断できると分かる(): void
    {
        config(['queue.connections.database.retry_after' => 600]);
        $company = $this->companyWithSource();
        $run = $company->analysisRuns()->create(['status' => RunStatus::Running]);
        $this->age($run, 3600);

        $this->get(route('companies.show', $company))
            ->assertInertia(fn ($page) => $page
                ->where('run.isRunning', true)
                ->where('run.isStuck', true)
                ->where('run.stuckAfterMinutes', 10)
            );
    }

    public function test_二重投入は書き込みロック下の再確認で弾かれる(): void
    {
        // 画面側のガードを通り抜けて同時に2回投入されても、Action 側の再確認で2本目を止める
        Queue::fake();
        $this->fakeBackupCommand();
        $company = $this->companyWithSource();
        $company->analysisRuns()->create(['status' => RunStatus::Queued]);

        $this->expectException(AnalysisBlockedException::class);
        app(RunAnalysisAction::class)->execute($company, 1);
    }

    public function test_締め切りがキューの再予約時間に収まらない構成では分析を弾く(): void
    {
        // retry_after が短いと同じ分析が二重に走って AI を二重に消費するため、黙って切り詰めず押す前に止める
        Queue::fake();
        config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 90]);
        Profile::create(['must_json' => ['年収400万以上']]);
        $company = $this->companyWithSource();

        $this->post("/companies/{$company->id}/analyze", ['panel_size' => 1])
            ->assertSessionHasErrors('analyze');

        $this->assertStringContainsString('DB_QUEUE_RETRY_AFTER', session('errors')->first('analyze'));
        Queue::assertNothingPushed();
    }

    public function test_補助ジョブも締め切りが収まらなければ投入時に止まる(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 90]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB_QUEUE_RETRY_AFTER');
        new GenerateBriefingJob(1);
    }
}
