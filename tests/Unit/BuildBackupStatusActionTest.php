<?php

namespace Tests\Unit;

use App\Actions\BuildBackupStatusAction;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * バックアップ状態集計の検証。利用者の実際の storage/backups に触れないよう、
 * 一時ディレクトリを storage として使う（BackupCommandTest と同じ方針）。
 */
class BuildBackupStatusActionTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/karte-backup-status-'.bin2hex(random_bytes(6));
        mkdir($this->storage.'/backups', 0700, true);
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storage.'/backups/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->storage.'/backups');
        rmdir($this->storage);
        parent::tearDown();
    }

    /** @return array{count: int, latestAt: string|null, totalBytes: int, stale: bool, unrecognizedCount: int} */
    private function backupStatus(): array
    {
        return (new BuildBackupStatusAction)->execute();
    }

    /** ファイル名の時刻規約（database-Ymd-His.sqlite）でバックアップファイルを置く */
    private function putBackup(Carbon $time, string $content = 'x'): void
    {
        file_put_contents($this->storage.'/backups/database-'.$time->format('Ymd-His').'.sqlite', $content);
    }

    public function test_バックアップ0件はstaleと判定される(): void
    {
        $status = $this->backupStatus();

        $this->assertSame(0, $status['count']);
        $this->assertNull($status['latestAt']);
        $this->assertSame(0, $status['totalBytes']);
        $this->assertTrue($status['stale']);
    }

    public function test_直近のバックアップがあればstaleにならない(): void
    {
        $latest = now()->subDay();
        $this->putBackup(now()->subDays(3), 'abc');
        $this->putBackup($latest, 'defgh');

        $status = $this->backupStatus();

        $this->assertSame(2, $status['count']);
        $this->assertSame($latest->format('Y-m-d H:i'), $status['latestAt'], '最新のファイル名の時刻が出る');
        $this->assertSame(8, $status['totalBytes'], '全世代の合計サイズ');
        $this->assertFalse($status['stale']);
    }

    public function test_最終バックアップが7日以上前ならstaleになる(): void
    {
        $this->putBackup(now()->subDays(8));

        $this->assertTrue($this->backupStatus()['stale']);
    }

    public function test_形式外のファイル名は例外にせず件数として返す(): void
    {
        // 過去に rsort 先頭の形式外名で Carbon::createFromFormat が例外→設定画面500になったバグの回帰テスト
        file_put_contents($this->storage.'/backups/database-manual-copy.sqlite', 'copy');
        $latest = now()->subDay();
        $this->putBackup($latest, 'abc');

        $status = $this->backupStatus();

        $this->assertSame(1, $status['count'], '形式外はバックアップとして数えない');
        $this->assertSame($latest->format('Y-m-d H:i'), $status['latestAt']);
        $this->assertSame(3, $status['totalBytes'], '形式外は合計サイズにも含めない');
        $this->assertSame(1, $status['unrecognizedCount']);
    }

    public function test_形式外しか無い場合はバックアップ0件扱いになる(): void
    {
        file_put_contents($this->storage.'/backups/database-manual-copy.sqlite', 'copy');

        $status = $this->backupStatus();

        $this->assertSame(0, $status['count']);
        $this->assertNull($status['latestAt']);
        $this->assertTrue($status['stale']);
        $this->assertSame(1, $status['unrecognizedCount']);
    }
}
