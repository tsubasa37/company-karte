<?php

namespace App\Services\Ai;

use App\Enums\AiProvider;

/**
 * 設定から解決した1回分の実行先（プロバイダ・モデル・固有オプション・上限秒）。
 */
final readonly class AiTarget
{
    /**
     * @param  string|null  $model  null はプロバイダの既定モデル（codex のみ許容）
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public AiProvider $provider,
        public ?string $model,
        public array $options,
        public int $timeout,
    ) {}
}
