<?php

namespace App\Services\Ai\Cost;

/**
 * 実行先1つぶんの見積もり結果。トークン・費用はすべて低め／高めの2通りで持つ
 * （換算率が実測なら低めと高めは同じ値になる）。
 *
 * 費用が null なのは「単価未登録」（config/ai.php の pricing にそのモデルが無い）ときだけで、
 * 0 では埋めない。トークン数は換算で必ず出せるので null にならない。
 */
final readonly class SimulationRow
{
    public function __construct(
        public SimulationTarget $target,
        /** 1社あたりの入力トークン（低め） */
        public int $inputTokensLow,
        public int $inputTokensHigh,
        public int $outputTokensLow,
        public int $outputTokensHigh,
        /** 1社あたりの費用（マイクロ USD）。単価未登録なら null */
        public ?int $perCompanyMicroUsdLow,
        public ?int $perCompanyMicroUsdHigh,
        /** 指定社数ぶんの合計（マイクロ USD）。単価未登録なら null */
        public ?int $totalMicroUsdLow,
        public ?int $totalMicroUsdHigh,
    ) {}

    /** 単価表からその実行先の費用を出せたか */
    public function isPriced(): bool
    {
        return $this->perCompanyMicroUsdLow !== null;
    }
}
