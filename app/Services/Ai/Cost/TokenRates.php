<?php

namespace App\Services\Ai\Cost;

/**
 * 文字数 → トークン数の換算率（トークン / 文字）。
 *
 * 実際の課金はトークン数で決まるが、ai_usages にトークンの記録が無い実行（codex など）では
 * 文字数しか残らない。そこで文字数から換算するが、率そのものが不確かなので低め・高めの2通りを持つ。
 * 実測（記録されたトークン数と文字数の比）が取れた場合は low と high が同じ値になる。
 */
final readonly class TokenRates
{
    public function __construct(
        public float $inputLow,
        public float $inputHigh,
        public float $outputLow,
        public float $outputHigh,
        /** 実測から出した率か（false なら config/ai.php の既定の幅） */
        public bool $measured,
        /** 実測の元になった実行の件数（既定の幅なら 0） */
        public int $sampleCount,
    ) {}
}
