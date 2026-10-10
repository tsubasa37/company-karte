<?php

namespace App\Services;

/**
 * 求人票の年収レンジ幅（下限と上限の開き）を PHP で確定計算する構造シグナル。
 *
 * 「年収400万〜1,200万」のように下限と上限が大きく開いたレンジは、比較表では
 * 上限だけが目に入り実際より魅力的に見える。倍率・差額の計算は AI に渡さず
 * ここで確定させ、画面は算出済みの数値をそのまま出す。
 *
 * 下限が null / 0 の場合は 0 除算を避けるため算出せず、算出できない理由を返す
 * （黙って 0 倍・1 倍として扱わない）。
 */
class SalaryRangeSignal
{
    /** レンジ幅の注意喚起をする倍率のしきい値（この値以上で注意表示。ユーザー決定値） */
    public const WIDE_RANGE_RATIO = 2.0;

    /**
     * @param  int|null  $min  年収下限（万円）。求人票に記載が無ければ null
     * @param  int|null  $max  年収上限（万円）。求人票に記載が無ければ null
     * @return array{ratio: float|null, spread: int|null, isWide: bool, hasRange: bool, unavailableReason: string|null, note: string|null}
     */
    public function evaluate(?int $min, ?int $max): array
    {
        // 下限・上限のどちらか一方でも数値があるか（両方無い＝レンジの主張自体が無い）
        $hasRange = $min !== null || $max !== null;

        $reason = $this->unavailableReason($min, $max, $hasRange);

        if ($reason !== null) {
            return [
                'ratio' => null,
                'spread' => null,
                'isWide' => false,
                'hasRange' => $hasRange,
                'unavailableReason' => $reason,
                'note' => null,
            ];
        }

        // しきい値判定は丸める前の値で行う（表示用の丸めで判定がぶれないように）
        $rawRatio = $max / $min;
        $isWide = $rawRatio >= self::WIDE_RANGE_RATIO;
        $ratio = round($rawRatio, 1);
        $spread = $max - $min;

        return [
            'ratio' => $ratio,
            'spread' => $spread,
            'isWide' => $isWide,
            'hasRange' => true,
            'unavailableReason' => null,
            // 断定はせず「下限で採用される可能性を面接で確認する」方向の喚起にとどめる
            'note' => $isWide
                ? sprintf('レンジ幅%.1f倍（%d万差）— 下限採用の可能性を確認', $ratio, $spread)
                : null,
        ];
    }

    /** 算出できない理由（算出できるなら null） */
    private function unavailableReason(?int $min, ?int $max, bool $hasRange): ?string
    {
        if (! $hasRange) {
            return '年収レンジの数値の記載が無いため、レンジ幅を算出できません';
        }

        if ($min === null) {
            return '年収下限の記載が無いため、レンジ幅を算出できません';
        }

        if ($min <= 0) {
            return "年収下限が{$min}万のため、レンジ幅を算出できません";
        }

        if ($max === null) {
            return '年収上限の記載が無いため、レンジ幅を算出できません';
        }

        return null;
    }
}
