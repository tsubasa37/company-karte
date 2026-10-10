<?php

namespace App\Services;

/**
 * 求人票から抽出した給与記載（P3 の salary_breakdown）から、基本給と実質時給を PHP で確定計算する。
 *
 * 数値・照合・確定計算は PHP で行い、codex には解釈・判定・要約だけをさせる方針のため、
 * ここでの算術を AI に暗算させない（AI 側は原文の数値を拾うだけ）。
 * 求人票に金額・みなし時間が書かれていないケースは多いので、推測値で埋めず
 * unavailable_reason に「なぜ算出できないか」を残す（サイレント失敗禁止）。
 */
class SalaryBreakdownCalculator
{
    /**
     * 実質時給の分母に使う月間所定労働時間。
     * 求人票側に所定労働時間の記載が無くても比較軸を固定するための共通値（ユーザー決定値）。
     */
    public const MONTHLY_WORK_HOURS = 173;

    /**
     * P3 出力の salary_breakdown に computed（確定計算の結果）を入れて返す。
     *
     * @param  array<string, mixed>  $p3Output
     * @return array<string, mixed>
     */
    public static function apply(array $p3Output): array
    {
        $breakdown = $p3Output['salary_breakdown'] ?? null;

        // AnalysisSchemas::p3 で必須にしているキー。無いのは契約違反なので黙って通さない
        if (! is_array($breakdown)) {
            throw new \RuntimeException('P3の出力に salary_breakdown がありません（スキーマ不一致）');
        }

        $breakdown['computed'] = self::compute($breakdown);
        $p3Output['salary_breakdown'] = $breakdown;

        return $p3Output;
    }

    /**
     * 抽出済みの給与記載から基本給・実質時給を求める。
     *
     * 実質時給 = 月給（総額） ÷ （月間所定労働時間 + 固定残業時間）。
     * 固定残業代込みの月給は「その残業時間まで働く前提の総額」なので、分母に固定残業時間を足す。
     * 月給がレンジの場合は下限（monthly_salary_min_yen）を使う（上限は「最大でこの額」であり、
     * 保守的に見るため）。
     *
     * @param  array<string, mixed>  $breakdown  P3 が抽出した salary_breakdown
     * @return array{monthly_work_hours: int, base_salary_yen: int|null, effective_hourly_yen: int|null, unavailable_reason: string|null}
     */
    public static function compute(array $breakdown): array
    {
        $monthly = self::intOrNull($breakdown['monthly_salary_min_yen'] ?? null, 'monthly_salary_min_yen');
        $hasFixedOvertime = self::bool($breakdown['has_fixed_overtime'] ?? null);
        $fixedHours = self::intOrNull($breakdown['fixed_overtime_hours'] ?? null, 'fixed_overtime_hours');
        $fixedAmount = self::intOrNull($breakdown['fixed_overtime_amount_yen'] ?? null, 'fixed_overtime_amount_yen');

        if ($monthly === null) {
            return self::unavailable('求人票に月給（月額）の記載が無いため算出不能（年収レンジのみの記載など）。月額が分かる求人票・採用サイトを投入すると算出できます');
        }
        if ($monthly <= 0) {
            return self::unavailable('抽出された月給が0円以下のため算出不能（求人票の記載を確認してください）');
        }

        if (! $hasFixedOvertime) {
            // 固定残業代の記載が無い前提なので、月給の全額を所定労働時間で割る
            return self::available($monthly, $monthly, self::MONTHLY_WORK_HOURS);
        }

        if ($fixedHours === null) {
            return self::unavailable('固定残業（みなし残業）の時間数が求人票に書かれていないため算出不能。「固定残業手当◯時間分」の記載があれば算出できます');
        }
        if ($fixedAmount === null) {
            return self::unavailable('固定残業代の金額が求人票に書かれていないため算出不能。「固定残業手当◯円」の記載があれば算出できます');
        }
        if ($fixedHours <= 0 || $fixedAmount <= 0) {
            return self::unavailable('固定残業ありの記載に対して時間数・金額が0以下のため算出不能（求人票の記載を確認してください）');
        }
        if ($fixedAmount >= $monthly) {
            return self::unavailable('固定残業代が月給以上の記載になっており整合が取れないため算出不能（求人票の記載を確認してください）');
        }

        return self::available($monthly - $fixedAmount, $monthly, self::MONTHLY_WORK_HOURS + $fixedHours);
    }

    /**
     * @return array{monthly_work_hours: int, base_salary_yen: int, effective_hourly_yen: int, unavailable_reason: null}
     */
    private static function available(int $baseSalary, int $monthlyTotal, int $totalHours): array
    {
        // 分母は MONTHLY_WORK_HOURS(>0) に正の固定残業時間を足した値なので0にならないが、
        // 万一0以下になったら計算を続けず落とす（0除算をサイレントに通さない）
        if ($totalHours <= 0) {
            throw new \RuntimeException("実質時給の分母が0以下になりました（totalHours={$totalHours}）");
        }

        return [
            'monthly_work_hours' => self::MONTHLY_WORK_HOURS,
            'base_salary_yen' => $baseSalary,
            'effective_hourly_yen' => (int) round($monthlyTotal / $totalHours),
            'unavailable_reason' => null,
        ];
    }

    /**
     * @return array{monthly_work_hours: int, base_salary_yen: null, effective_hourly_yen: null, unavailable_reason: string}
     */
    private static function unavailable(string $reason): array
    {
        return [
            'monthly_work_hours' => self::MONTHLY_WORK_HOURS,
            'base_salary_yen' => null,
            'effective_hourly_yen' => null,
            'unavailable_reason' => $reason,
        ];
    }

    /**
     * スキーマ上 integer|null の項目。文字列や小数で来たら型を丸めず例外にする
     * （"25万" のような表記を勝手に解釈すると誤った金額を確定値として保存してしまう）。
     */
    private static function intOrNull(mixed $value, string $key): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }

        throw new \RuntimeException("給与記載 {$key} が整数ではありません（スキーマ不一致 / ".get_debug_type($value).'）');
    }

    private static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        throw new \RuntimeException('給与記載 has_fixed_overtime が真偽値ではありません（スキーマ不一致 / '.get_debug_type($value).'）');
    }
}
