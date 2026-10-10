<?php

namespace App\Services\Ai\Driver;

use App\Enums\AiProvider;

/**
 * ドライバが返す1回分の応答。data はスキーマ検証前の生の配列。
 */
final readonly class AiResponse
{
    /**
     * @param  array<string, mixed>  $data
     * @param  TokenUsage|null  $tokens  取れなかったプロバイダ・版では null（0 と区別する）
     */
    public function __construct(
        public array $data,
        public ?TokenUsage $tokens,
        public AiProvider $provider,
        public ?string $model,
    ) {}
}
