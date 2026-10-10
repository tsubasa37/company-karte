<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * codex CLI が残すセッションログから、ChatGPTサブスクの利用枠の消費率を読む。
 *
 * codex には使用量を返すコマンドが無いが、実行のたびに
 * ~/.codex/sessions/YYYY/MM/DD/rollout-*.jsonl へ rate_limits を書き出している。
 * この数値は codex を使う全ツール（このアプリ・Claude Code・手動実行）の合計で、
 * このアプリだけの消費ではない点に注意。
 *
 * 形式は codex 側の都合で変わりうるため、読めないときは推測で埋めず理由を返す
 * （数字を作ると「まだ余裕がある」と誤読させるため）。
 */
class CodexRateLimitReader
{
    /** 走査するログの新しい順の件数（全件読むと数百MBになるため） */
    private const MAX_FILES = 12;

    /** 1ファイルから読む末尾のバイト数（rate_limits は実行の最後に書かれる） */
    private const TAIL_BYTES = 262144;

    /**
     * @return array{
     *     available: bool,
     *     usedPercent: float|null,
     *     windowLabel: string|null,
     *     resetsAt: string|null,
     *     planType: string|null,
     *     observedAt: string|null,
     *     unavailableReason: string|null,
     * }
     */
    public function read(): array
    {
        $dir = $this->sessionsDir();

        if (! is_dir($dir)) {
            return $this->unavailable("codex のセッションログが見つかりません（{$dir}）");
        }

        foreach ($this->recentFiles($dir) as $file) {
            $limits = $this->lastRateLimits($file);
            if ($limits !== null) {
                return $limits;
            }
        }

        return $this->unavailable('セッションログに利用枠の記録が見つかりませんでした（codex の出力形式が変わった可能性があります）');
    }

    /** 新しい順のログファイル（最大 MAX_FILES 件） */
    private function recentFiles(string $dir): array
    {
        $files = glob($dir.'/*/*/*/rollout-*.jsonl') ?: [];

        // ファイル名に実行日時が入っているため、名前の降順が新しい順になる
        rsort($files);

        return array_slice($files, 0, self::MAX_FILES);
    }

    /**
     * ファイル末尾から最後の rate_limits を取り出す。無ければ null。
     *
     * @return array<string, mixed>|null
     */
    private function lastRateLimits(string $file): ?array
    {
        $tail = $this->tail($file);
        $found = null;

        foreach (explode("\n", $tail) as $line) {
            if (! str_contains($line, '"rate_limits"')) {
                continue;
            }

            $row = json_decode($line, true);
            $limits = $row['payload']['rate_limits'] ?? null;
            $primary = $limits['primary'] ?? null;

            if (is_array($primary) && isset($primary['used_percent'])) {
                $found = $this->format($limits, $primary, (string) ($row['timestamp'] ?? ''));
            }
        }

        return $found;
    }

    /** 末尾 TAIL_BYTES ぶんを読む（先頭が行の途中で切れても、その行は json_decode で捨てられる） */
    private function tail(string $file): string
    {
        $size = filesize($file);
        if ($size === false) {
            return '';
        }

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return '';
        }

        try {
            if ($size > self::TAIL_BYTES) {
                fseek($handle, -self::TAIL_BYTES, SEEK_END);
            }

            return (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string, mixed>  $limits
     * @param  array<string, mixed>  $primary
     * @return array<string, mixed>
     */
    private function format(array $limits, array $primary, string $timestamp): array
    {
        $minutes = (int) ($primary['window_minutes'] ?? 0);
        $resets = $primary['resets_at'] ?? null;

        return [
            'available' => true,
            'usedPercent' => (float) $primary['used_percent'],
            'windowLabel' => $minutes > 0 ? $this->windowLabel($minutes) : null,
            'resetsAt' => is_numeric($resets) ? Carbon::createFromTimestamp((int) $resets)->format('Y-m-d H:i') : null,
            'planType' => isset($limits['plan_type']) ? (string) $limits['plan_type'] : null,
            'observedAt' => $timestamp !== '' ? Carbon::parse($timestamp)->format('Y-m-d H:i') : null,
            'unavailableReason' => null,
        ];
    }

    /** 枠の期間（分）を読める単位にする */
    private function windowLabel(int $minutes): string
    {
        if ($minutes % 1440 === 0) {
            return intdiv($minutes, 1440).'日';
        }

        return $minutes % 60 === 0 ? intdiv($minutes, 60).'時間' : $minutes.'分';
    }

    /** @return array<string, mixed> */
    private function unavailable(string $reason): array
    {
        return [
            'available' => false,
            'usedPercent' => null,
            'windowLabel' => null,
            'resetsAt' => null,
            'planType' => null,
            'observedAt' => null,
            'unavailableReason' => $reason,
        ];
    }

    private function sessionsDir(): string
    {
        return rtrim((string) config('services.codex.home'), '/').'/sessions';
    }
}
