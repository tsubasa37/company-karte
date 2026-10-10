<?php

namespace App\Services\Ai\Cost;

use App\Enums\AiPurpose;
use App\Enums\AiTier;
use App\Enums\AnalysisStep;

/**
 * 見積もりに含める AI 実行1種類ぶん（用途 × 内訳）の規模。
 *
 * 文字数は「その用途で過去に実際に走った実行の平均」か、記録が無ければ既定値（CostSimulator の実測値）。
 * どちらを使ったかは measured / sampleCount で持ち、画面と CLI が「実測 n 件から / 既定値」と出せるようにする。
 */
final readonly class WorkloadItem
{
    public function __construct(
        public AiPurpose $purpose,
        /** 用途の中の内訳（分析なら p1..p5）。内訳が無い用途は null */
        public ?string $detail,
        /** 実行の重さ。実行先は設定画面で Tier ごとに選ぶため、どちらの設定が効くかを示す */
        public AiTier $tier,
        /** 1社あたりの実行回数（P2 は判定人数ぶん） */
        public int $callsPerCompany,
        public int $promptChars,
        public int $outputChars,
        /** 文字数が実測から来ているか（false なら既定値） */
        public bool $measured,
        /** 実測の元になった実行の件数（既定値なら 0） */
        public int $sampleCount,
    ) {}

    public function label(): string
    {
        if ($this->detail === null) {
            return $this->purpose->label();
        }

        $step = AnalysisStep::from($this->detail);

        return mb_strtoupper($step->value).' '.$step->label();
    }

    /** 文字数の出所（CLI・画面にそのまま出す） */
    public function charSourceLabel(): string
    {
        return $this->measured ? "実測 {$this->sampleCount}件から" : '既定値';
    }
}
