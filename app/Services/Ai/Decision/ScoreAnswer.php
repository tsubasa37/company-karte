<?php

namespace App\Services\Ai\Decision;

/**
 * score の回答。score は levels の 1 始まりの位置（小数あり）。
 */
final readonly class ScoreAnswer
{
    /**
     * @param  list<float>  $probabilities  levels の並びに正規化済み
     * @param  float  $confidence  0〜1
     */
    public function __construct(
        public float $score,
        public array $probabilities,
        public float $confidence,
    ) {}
}
