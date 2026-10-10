<?php

namespace App\Services\Ai\Cost;

/**
 * 料金シミュレーションの結果一式。数値はすべて CostSimulator が確定済みで、
 * 画面・CLI は単位の変換（マイクロ USD → USD）と並べ方だけを行う。
 */
final readonly class SimulationResult
{
    /**
     * @param  array<int, SimulationRow>  $rows  安い順（単価未登録は末尾）
     * @param  array<int, WorkloadItem>  $workload  見積もりに含めた実行の内訳
     * @param  string|null  $charSourceTable  文字数の実測を引いた表。実測が1件も無ければ null
     */
    public function __construct(
        public array $rows,
        public array $workload,
        public TokenRates $rates,
        public SimulationInput $input,
        public ?string $charSourceTable,
    ) {}

    /** 1社あたりの AI 実行回数（codex のサブスク枠の消費量でもある） */
    public function callsPerCompany(): int
    {
        return array_sum(array_map(fn (WorkloadItem $item) => $item->callsPerCompany, $this->workload));
    }
}
