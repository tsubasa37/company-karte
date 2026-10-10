<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * VACUUM INTO はトランザクション内で実行できないため、このテストは RefreshDatabase を使わない
 * （マイグレーション不要・DBを変更しないコマンドのため問題ない）。
 *
 * 全テストで実際の storage/backups ではなく一時ディレクトリを storage として使う。
 * 実パスへの glob/unlink を書いてはいけない（過去に setUp/tearDown が利用者の
 * 実バックアップを全削除するバグがあったため）。
 */
class BackupCommandTest extends TestCase
{
    /** 一時 storage 配下の backups ディレクトリ（コマンドが作るため setUp 時点では未作成） */
    private string $directory;

    /** テストごとに storage として使う一時ディレクトリ */
    private string $temporaryStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = $this->useTemporaryStorage();
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryStorage();
        parent::tearDown();
    }

    /** @return array<int, string> */
    private function backups(): array
    {
        return glob($this->directory.'/database-*.sqlite') ?: [];
    }

    /**
     * 一時ディレクトリを storage として使う（利用者のバックアップに触れないため）。
     * 戻り値は一時 storage 配下の backups ディレクトリのパス（この時点では未作成）。
     */
    private function useTemporaryStorage(): string
    {
        $this->temporaryStorage = sys_get_temp_dir().'/karte-backup-'.bin2hex(random_bytes(6));
        mkdir($this->temporaryStorage, 0700, true);
        $this->app->useStoragePath($this->temporaryStorage);

        return $this->temporaryStorage.'/backups';
    }

    private function removeTemporaryStorage(): void
    {
        foreach (glob($this->temporaryStorage.'/backups/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->temporaryStorage.'/backups')) {
            rmdir($this->temporaryStorage.'/backups');
        }
        rmdir($this->temporaryStorage);
    }

    /** パーミッションのビット（0700 等）だけを取り出す */
    private function permissions(string $path): int
    {
        clearstatcache(true, $path);

        return fileperms($path) & 0777;
    }

    public function test_バックアップファイルが作られる(): void
    {
        // テストは :memory: DB のため、ファイルへ書き出せることの確認になる
        $this->artisan('karte:backup')->assertSuccessful();

        $this->assertCount(1, $this->backups());
    }

    public function test_世代数を超えた古いバックアップは削除される(): void
    {
        // 古い世代を先に用意しておく（ファイル名の時刻が古いものから消える）
        mkdir($this->directory, 0700, true);
        foreach (['20200101-000000', '20200102-000000'] as $stamp) {
            touch($this->directory."/database-{$stamp}.sqlite");
        }

        $this->artisan('karte:backup', ['--keep' => 2])->assertSuccessful();

        $files = $this->backups();
        $this->assertCount(2, $files, '最新2世代だけ残る');
        $this->assertStringNotContainsString('20200101', implode(' ', $files), '最も古いものが消える');
    }

    public function test_既定では最新5世代が残り古いものから置き換えられる(): void
    {
        // 溜め続けない（--keep 未指定でも上限あり）が、分析のたびに作る復元点が1回で消えない世代数にする
        mkdir($this->directory, 0700, true);
        foreach (['20200101-000000', '20200102-000000', '20200103-000000', '20200104-000000', '20200105-000000'] as $stamp) {
            touch($this->directory."/database-{$stamp}.sqlite");
        }

        $this->artisan('karte:backup')->assertSuccessful();

        $files = $this->backups();
        $this->assertCount(5, $files, '既定は5世代を保持');
        $this->assertStringNotContainsString('20200101', implode(' ', $files), '最も古い世代だけ消える');
        $this->assertStringContainsString('20200102', implode(' ', $files));
    }

    public function test_同じ秒に連続実行しても失敗しない(): void
    {
        // ファイル名は秒精度。分析を続けて実行すると同じ秒に2回走りうる。
        // VACUUM INTO は既存ファイルがあるとエラーになるため、一時ファイル経由で置き換える
        Carbon::setTestNow('2026-08-02 11:25:31');

        $this->artisan('karte:backup')->assertSuccessful();
        $this->artisan('karte:backup')->assertSuccessful();

        $this->assertCount(1, $this->backups(), '同名でも置き換わって1件のまま');
    }

    public function test_古い一時ファイルは掃除され新しいものは残る(): void
    {
        mkdir($this->directory, 0700, true);
        $stale = $this->directory.'/database-20200101-000000.sqlite.aaaaaa.tmp';
        $fresh = $this->directory.'/database-20200101-000000.sqlite.bbbbbb.tmp';
        touch($stale, time() - 7200);
        touch($fresh);

        $this->artisan('karte:backup')->assertSuccessful();

        $this->assertFileDoesNotExist($stale, '1時間より古い一時ファイルは消える');
        $this->assertFileExists($fresh, '実行中かもしれない新しい一時ファイルは消さない');
    }

    public function test_バックアップ先ディレクトリは0700で作られる(): void
    {
        $this->artisan('karte:backup')->assertSuccessful();

        $this->assertSame(0700, $this->permissions($this->directory));
    }

    public function test_既存ディレクトリの緩い権限は0700に締め直される(): void
    {
        mkdir($this->directory, 0755, true);
        chmod($this->directory, 0755); // mkdir は umask に削られるため明示的に緩めておく

        $this->artisan('karte:backup')->assertSuccessful();

        $this->assertSame(0700, $this->permissions($this->directory));
    }

    public function test_バックアップファイルは0600で作られる(): void
    {
        // 全PII を含む平文コピーのため、本人以外に読めてはいけない
        $this->artisan('karte:backup')->assertSuccessful();

        $files = $this->backups();
        $this->assertCount(1, $files);
        $this->assertSame(0600, $this->permissions($files[0]));
    }
}
