<?php

namespace App\Services\Ai\Decision;

/**
 * noul の回答（0〜1）。confidence は返らない。
 */
final readonly class NoulAnswer
{
    public function __construct(
        public float $noul,
    ) {}
}
