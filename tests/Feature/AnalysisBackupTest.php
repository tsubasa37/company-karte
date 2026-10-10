<?php

namespace Tests\Feature;

use App\Actions\RunAnalysisAction;
use App\Enums\AiProvider;
use App\Jobs\AnalyzeCompanyJob;
use App\Models\AiSetting;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 分析実行前の自動バックアップ（スケジューラを常駐させない代わりの復元点作り）。
 *
 * karte:backup 本体は VACUUM INTO を使うためトランザクション内（RefreshDatabase）では
 * 実行できない。ここでは同名のクロージャコマンドで差し替えて「呼ばれること」と
 * 「失敗したら分析を開始しないこと」を確かめる（コマンド本体は BackupCommandTest が検証）。
 */
class AnalysisBackupTest extends TestCase
{
    use RefreshDatabase;

    /** karte:backup を差し替える。$exitCode を返し、呼ばれた回数を数える */
    private function fakeBackup(int $exitCode, ?int &$calls): void
    {
        $calls = 0;
        Artisan::command('karte:backup {--keep=7}', function () use ($exitCode, &$calls) {
            $calls++;

            return $exitCode;
        });
    }

    public function test_分析実行前にバックアップが呼ばれる(): void
    {
        Queue::fake();
        $this->fakeBackup(0, $calls);
        $company = Company::create(['name' => '株式会社テスト']);

        app(RunAnalysisAction::class)->execute($company, 1);

        $this->assertSame(1, $calls);
        Queue::assertPushed(AnalyzeCompanyJob::class);
    }

    public function test_判定エンジンがオフなら口コミの機械判定の待機行を作らない(): void
    {
        Queue::fake();
        $this->fakeBackup(0, $calls);
        $company = Company::create(['name' => '株式会社テスト']);

        $run = app(RunAnalysisAction::class)->execute($company, 1);

        // 待機中や P1 で止まったランの進捗に、使っていない機能の行を出さない
        $this->assertSame(['p1', 'p2', 'p3', 'p4', 'p5'], $run->steps()->orderBy('id')->pluck('step')->map->value->all());
    }

    public function test_判定エンジンがオンなら口コミの機械判定の待機行を作る(): void
    {
        Queue::fake();
        $this->fakeBackup(0, $calls);
        AiSetting::sole()->update(['decision_provider' => AiProvider::Jev]);
        $company = Company::create(['name' => '株式会社テスト']);

        $run = app(RunAnalysisAction::class)->execute($company, 1);

        $this->assertSame(['p1', 'rj', 'p2', 'p3', 'p4', 'p5'], $run->steps()->orderBy('id')->pluck('step')->map->value->all());
    }

    public function test_バックアップが失敗したら分析を開始しない(): void
    {
        Queue::fake();
        $this->fakeBackup(1, $calls);
        $company = Company::create(['name' => '株式会社テスト']);

        // 握り潰さず例外にする（復元点が無いまま破壊的な分析を走らせない）
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('分析前のバックアップ（karte:backup）が失敗したため分析を開始しません');

        try {
            app(RunAnalysisAction::class)->execute($company, 1);
        } finally {
            $this->assertSame(0, $company->analysisRuns()->count());
            Queue::assertNothingPushed();
        }
    }
}
