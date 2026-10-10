<?php

namespace App\Services\Ai\Decision;

/**
 * choice の回答。確率の生数値は画面に出さない（判定の根拠として内部で使うだけ）。
 */
final readonly class ChoiceAnswer
{
    /**
     * @param  array<string, float>  $probabilities  候補名 => 確率
     * @param  float  $confidence  0〜1
     */
    public function __construct(
        public string $choice,
        public array $probabilities,
        public float $confidence,
    ) {}
}
