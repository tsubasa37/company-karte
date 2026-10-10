<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SQLite の DB ファイルをバックアップする。
 * VACUUM INTO を使うため、実行中でも整合性のあるコピーが作れる（ファイルコピーと違い壊れない）。
 */
class BackupDatabase extends Command
{
    /**
     * 既定の世代数。分析のたびに復元点を作るため、1世代だと2回目の分析で「1回目の前」に戻れなくなる。
     * DB は数百 KB なので5世代でも容量は無視できる。
     */
    public const DEFAULT_KEEP = 5;

    protected $signature = 'karte:backup {--keep='.self::DEFAULT_KEEP.' : 残す世代数}';

    protected $description = 'カルテのデータベースをバックアップする（既定は'.self::DEFAULT_KEEP.'世代・古いものから置き換え）';

    public function handle(): int
    {
        $keep = max(1, (int) $this->option('keep'));
        $directory = storage_path('backups');

        $this->prepareDirectory($directory);

        $path = $directory.'/database-'.now()->format('Ymd-His').'.sqlite';

        // VACUUM INTO はコピー先が存在するとエラーになる（上書きしない安全側の仕様）。
        // ファイル名は秒精度なので、同じ秒に2回走ると衝突して失敗する（分析を連続実行すると起きる）。
        // 一時ファイルへ書き出してから rename で差し替え、衝突を避けつつ原子的に置き換える。
        // 拡張子が .tmp なので、途中の状態が世代管理（database-*.sqlite）に混ざることはない。
        $temp = $path.'.'.bin2hex(random_bytes(6)).'.tmp';

        DB::statement('VACUUM INTO ?', [$temp]);

        try {
            // VACUUM INTO の生成物の権限は umask 依存（通常 0644）。
            // 全PII（職務経歴・年収・口コミ本文）を含む平文コピーなので本人だけが読めるようにする。
            // 最終パスへ移す前に締めるので、緩い権限で置かれる瞬間を作らない
            $this->chmodOrFail($temp, 0600, 'バックアップファイル');

            if (! rename($temp, $path)) {
                throw new \RuntimeException("バックアップを配置できませんでした: {$path}");
            }
        } catch (\Throwable $e) {
            // 中途半端な一時ファイルを残さない（失敗そのものは握り潰さず再送出する）
            @unlink($temp);

            throw $e;
        }

        $this->info('バックアップを作成しました: '.$path);
        $this->info('サイズ: '.number_format((int) (filesize($path) / 1024)).' KB');

        $this->pruneStaleTempFiles($directory);
        $deleted = $this->pruneOldBackups($directory, $keep);
        if ($deleted > 0) {
            $this->info("古いバックアップを{$deleted}件削除しました（{$keep}世代を保持）");
        }

        return self::SUCCESS;
    }

    /** バックアップ先を用意し、本人以外が覗けない権限（0700）に揃える */
    private function prepareDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException("バックアップ先を作成できませんでした: {$directory}");
        }

        // mkdir のモードは umask に削られ、また過去に 0755 で作られている場合もあるため毎回締め直す
        $this->chmodOrFail($directory, 0700, 'バックアップ先');
    }

    /**
     * 権限を締め直し、失敗したら原因付きの例外にする。
     *
     * chmod は失敗時にまず PHP の警告を出す。警告は Laravel の例外ハンドラで ErrorException に
     * 変換されるため、戻り値 false を見る自前のチェックには到達せず、英語の汎用メッセージで落ちていた。
     * そこで chmod の間だけ警告を自前で受け取り、原因（OS のエラー文言）を添えた例外に一本化する。
     * 失敗を握り潰すのではなく、必ず例外として投げる点は変えていない。
     */
    private function chmodOrFail(string $path, int $mode, string $label): void
    {
        $reason = null;
        set_error_handler(function (int $severity, string $message) use (&$reason): bool {
            $reason = $message;

            // true を返して既定ハンドラ（ErrorException への変換）に流さない
            return true;
        });

        try {
            $ok = chmod($path, $mode);
        } finally {
            restore_error_handler();
        }

        if ($ok) {
            return;
        }

        throw new \RuntimeException(
            sprintf('%sの権限を %04o にできませんでした: %s（%s）', $label, $mode, $path, $reason ?? '原因不明')
        );
    }

    /**
     * 書き出し中にプロセスが落ちると .tmp が残る。放置すると溜まり続けるため、
     * 実行中のものを巻き込まない十分な猶予（1時間）を過ぎたものだけを消す。
     * 600KB 程度の DB で VACUUM が1時間かかることはない。
     */
    private function pruneStaleTempFiles(string $directory): void
    {
        $threshold = time() - 3600;

        foreach (glob($directory.'/database-*.tmp') ?: [] as $temp) {
            if (filemtime($temp) < $threshold) {
                @unlink($temp);
            }
        }
    }

    /** 新しい順に $keep 件だけ残し、それより古いものを削除する */
    private function pruneOldBackups(string $directory, int $keep): int
    {
        $files = glob($directory.'/database-*.sqlite') ?: [];

        // ファイル名に時刻が入っているため、名前の降順が新しい順になる
        rsort($files);

        $deleted = 0;
        foreach (array_slice($files, $keep) as $old) {
            unlink($old);
            $deleted++;
        }

        return $deleted;
    }
}
