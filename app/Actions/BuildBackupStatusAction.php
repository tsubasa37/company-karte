<?php

namespace App\Actions;

use Illuminate\Support\Carbon;

/**
 * 設定画面に出すバックアップ状態（件数・最終日時・合計サイズ・停滞判定）を集計する。
 * 件数・日時・停滞判定はすべてPHPで確定し、フロントには表示だけをさせる。
 */
class BuildBackupStatusAction
{
    /** バックアップが無い日がこれだけ続いたら停滞（手動バックアップの促し）とみなす */
    private const STALE_DAYS = 7;

    /** バックアップとして扱うファイル名（BackupDatabase の命名規約 database-Ymd-His.sqlite） */
    private const NAME_PATTERN = '/^database-\d{8}-\d{6}\.sqlite$/';

    /**
     * @return array{count: int, latestAt: string|null, totalBytes: int, stale: bool, unrecognizedCount: int}
     */
    public function execute(): array
    {
        $all = glob(storage_path('backups').'/database-*.sqlite') ?: [];

        // 命名規約に合致するものだけをバックアップとして集計する。
        // 形式外（手動コピー等）は黙って無視せず、件数として返して画面に表示する。
        $files = array_values(array_filter(
            $all,
            fn (string $f) => preg_match(self::NAME_PATTERN, basename($f)) === 1,
        ));

        $latest = $this->latestTimestamp($files);

        return [
            'count' => count($files),
            'latestAt' => $latest?->format('Y-m-d H:i'),
            'totalBytes' => array_sum(array_map(fn (string $f) => (int) filesize($f), $files)),
            'stale' => $latest === null || $latest->lte(now()->subDays(self::STALE_DAYS)),
            'unrecognizedCount' => count($all) - count($files),
        ];
    }

    /**
     * 最新バックアップの作成日時。ファイル名の時刻（database-Ymd-His.sqlite）から確定する。
     * 呼び出し前に NAME_PATTERN で形式を絞ってあるため必ずパースできる。0件なら null。
     *
     * @param  array<int, string>  $files
     */
    private function latestTimestamp(array $files): ?Carbon
    {
        if ($files === []) {
            return null;
        }

        // ファイル名に時刻が入っているため、名前の降順が新しい順になる（BackupDatabase と同じ規約）
        rsort($files);
        $stamp = substr(basename($files[0], '.sqlite'), strlen('database-'));

        return Carbon::createFromFormat('Ymd-His', $stamp);
    }
}
