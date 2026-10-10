<?php

namespace Tests\Feature;

use App\Http\Controllers\BackupController;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * 「今すぐバックアップ」の検証。ルート POST /settings/backup（settings.backup.store）経由で叩く。
 *
 * VACUUM INTO はトランザクション内で実行できないため RefreshDatabase を使わない
 * （BackupCommandTest と同じ）。実バックアップに触れないよう一時 storage を使う。
 */
class BackupControllerTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/karte-backup-ctrl-'.bin2hex(random_bytes(6));
        mkdir($this->storage, 0700, true);
        $this->app->useStoragePath($this->storage);
        // RefreshDatabase を使えない代わりに手動でマイグレーションする
        // （ルート経由の検証には AutoLoginOwner が読む users テーブルが要る。
        //   トランザクションで包まれないため VACUUM INTO も実行できる）
        $this->artisan('migrate');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storage.'/backups/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->storage.'/backups')) {
            rmdir($this->storage.'/backups');
        }
        rmdir($this->storage);
        parent::tearDown();
    }

    public function test_バックアップが実行されリダイレクトが返る(): void
    {
        $this->post(route('settings.backup.store'))->assertRedirect();

        $this->assertCount(1, glob($this->storage.'/backups/database-*.sqlite') ?: []);
    }

    public function test_コマンド失敗は例外になる（サイレント失敗禁止）(): void
    {
        Artisan::shouldReceive('call')->once()->with('karte:backup')->andReturn(1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('karte:backup が失敗しました');

        // HTTP を通すと例外ハンドラが 500 に変換して握り潰しの検知が緩むため、ここは直接呼ぶ
        app(BackupController::class)->store();
    }
}
