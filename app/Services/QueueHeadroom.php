<?php

namespace App\Services;

/**
 * ジョブの締め切り（timeout）がキューの再予約時間（retry_after）に収まるかの判定を1か所に集める。
 *
 * database ドライバは retry_after 秒を過ぎた実行中ジョブを「死んだ」とみなして別ワーカーに拾わせる。
 * timeout がそれより長いと、AI の実行中に同じジョブが二重に走り、費用が黙って倍になる。
 * 黙って切り詰めたりログに書くだけにせず、投入前に例外（または画面の文言）で止める。
 */
class QueueHeadroom
{
    /** 締め切りと再予約時間の間に置く余裕（秒）。ワーカーが失敗処理を書き込む時間を含む */
    public const MARGIN_SECONDS = 60;

    /** 二重実行が起きるのは database ドライバだけ（sync は逐次実行。テストも sync） */
    public function isDatabaseQueue(): bool
    {
        return config('queue.default') === 'database';
    }

    public function retryAfter(): int
    {
        $value = config('queue.connections.database.retry_after');

        if (! is_numeric($value) || (int) $value <= 0) {
            throw new \RuntimeException(
                'キューの再予約時間（queue.connections.database.retry_after / DB_QUEUE_RETRY_AFTER）が設定されていません'
            );
        }

        return (int) $value;
    }

    /** 収まらないときの理由（収まるなら null）。画面に出す文言と例外の文言を兼ねる */
    public function shortfall(int $timeoutSeconds, string $label): ?string
    {
        if (! $this->isDatabaseQueue()) {
            return null;
        }

        $retryAfter = $this->retryAfter();

        if ($timeoutSeconds + self::MARGIN_SECONDS <= $retryAfter) {
            return null;
        }

        return "{$label}の締め切り {$timeoutSeconds} 秒がキューの再予約時間（DB_QUEUE_RETRY_AFTER={$retryAfter} 秒）に収まりません。"
            .'このまま実行すると同じ処理が二重に走って AI を二重に消費するため実行しません。'
            .'.env の DB_QUEUE_RETRY_AFTER を '.($timeoutSeconds + self::MARGIN_SECONDS)
            .' 以上にして php artisan queue:work を再起動してください。';
    }

    public function assertFits(int $timeoutSeconds, string $label): void
    {
        $shortfall = $this->shortfall($timeoutSeconds, $label);

        if ($shortfall !== null) {
            throw new \RuntimeException($shortfall);
        }
    }
}
