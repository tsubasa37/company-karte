<?php

namespace App\Services\Ai\Driver;

/**
 * トークン数（全ドライバ共通の意味に正規化済み）。無い欄は null で、0 にしない。
 *
 * - input      = 総入力（cached・cacheWrite を含む）
 * - cached     = うちキャッシュ読み取り
 * - cacheWrite = うちキャッシュ書き込み
 * - output     = 課金対象の全出力（可視 + 思考）
 * - reasoning  = うち思考（output の内数）
 */
final readonly class TokenUsage
{
    /**
     * @param  array<string, mixed>|null  $raw  プロバイダが返した usage の生値（正規化の検証・再計算用）
     */
    public function __construct(
        public ?int $input = null,
        public ?int $cached = null,
        public ?int $cacheWrite = null,
        public ?int $output = null,
        public ?int $reasoning = null,
        public ?array $raw = null,
    ) {}
}
