<?php

namespace App\Services\Ai\Cost;

/**
 * 料金シミュレーションの入力。回数は「1社ぶんの分析1回 + それに付随する自動実行」を1単位として数える。
 *
 * 既定の回数は 2026-09-03 時点の実測（codex_usages・1社ぶんの分析1回とその前後の自動実行）に合わせてある。
 * 口コミを何件貼るか・クリップを何回押すかは企業ごとに違うので、実際の運用に合わせて上書きして使う。
 */
final readonly class SimulationInput
{
    /** 口コミ分割の既定回数（実測: 口コミソース1件を分割した1回） */
    public const DEFAULT_REVIEW_SPLITS = 1;

    /** クリップ判定の既定回数（実測: 拡張のボタンを2回押した2回） */
    public const DEFAULT_CLIP_CLASSIFIES = 2;

    /** 労働条件の抽出の既定回数（実測: 求人票1件に対する1回） */
    public const DEFAULT_CONDITION_EXTRACTS = 1;

    /**
     * @param  array<int, SimulationTarget>  $targets  見積もる実行先。空だと表が出せないので受け付けない
     * @param  int  $companies  合計を出す企業数
     * @param  int  $panelSize  裏取り（P2）の判定人数。この人数ぶん P2 が独立に実行される
     * @param  int  $reviewSplits  1社あたりの口コミ分割の回数
     * @param  int  $clipClassifies  1社あたりのクリップ判定の回数
     * @param  int  $conditionExtracts  1社あたりの労働条件の抽出の回数
     * @param  bool  $includeAuto  付随して自動で走る実行（口コミ分割・クリップ判定・労働条件の抽出）を含めるか
     */
    public function __construct(
        public array $targets,
        public int $companies = 1,
        public int $panelSize = 1,
        public int $reviewSplits = self::DEFAULT_REVIEW_SPLITS,
        public int $clipClassifies = self::DEFAULT_CLIP_CLASSIFIES,
        public int $conditionExtracts = self::DEFAULT_CONDITION_EXTRACTS,
        public bool $includeAuto = true,
    ) {
        if ($targets === []) {
            throw new \InvalidArgumentException('見積もりの対象になる実行先が1つもありません');
        }

        foreach ($targets as $target) {
            if (! $target instanceof SimulationTarget) {
                throw new \InvalidArgumentException('targets には SimulationTarget だけを渡してください');
            }
        }

        $this->assertAtLeast('companies', $companies, 1);
        $this->assertAtLeast('panelSize', $panelSize, 1);
        $this->assertAtLeast('reviewSplits', $reviewSplits, 0);
        $this->assertAtLeast('clipClassifies', $clipClassifies, 0);
        $this->assertAtLeast('conditionExtracts', $conditionExtracts, 0);
    }

    private function assertAtLeast(string $name, int $value, int $min): void
    {
        if ($value < $min) {
            throw new \InvalidArgumentException("{$name} は {$min} 以上で指定してください（指定値: {$value}）");
        }
    }
}
