<?php

namespace App\Services\Ai\Driver;

use App\Enums\AiProvider;

/**
 * 課金済みの失敗（出力上限での打ち切り・refusal・安全性ブロック等）。
 * トークンを消費した応答なので usage を載せて投げ、AiRunner が記録に残す。
 * 4xx / 429 / 接続失敗のように課金されない失敗は usage = null でよい。
 */
class AiRequestFailed extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly AiProvider $provider,
        public readonly ?string $model = null,
        public readonly ?TokenUsage $usage = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
