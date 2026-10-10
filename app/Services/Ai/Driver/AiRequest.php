<?php

namespace App\Services\Ai\Driver;

/**
 * ドライバに渡す1回分の実行内容。
 */
final readonly class AiRequest
{
    /**
     * @param  array<string, mixed>  $schema  構造化出力スキーマ（root は object）
     * @param  string|null  $model  null ならプロバイダの既定モデルに任せる（codex のみ許容）
     * @param  array<string, mixed>  $options  プロバイダ固有の設定（推論強度など）。ドライバは自分の鍵だけ読む
     * @param  int  $timeout  1回の呼び出しの上限秒
     */
    public function __construct(
        public array $schema,
        public string $prompt,
        public ?string $model,
        public array $options,
        public int $timeout,
    ) {}
}
