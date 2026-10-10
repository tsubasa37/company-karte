<?php

namespace App\Services;

use App\Models\Company;
use App\Models\OfficialMetric;
use Illuminate\Support\Collection;

/**
 * 公式数値（平均年間給与など）を、登録済みの他社と並べて位置を出す。
 *
 * 比較の母集団は「自分が登録した企業のうち、同じ項目・同じ単位の数値を持つ企業」であり、
 * 業界平均でも市場全体でもない。数値の出どころは official_metrics だけで、外部から
 * 統計値を取ってきたり、平均値を定数として持ったりはしない（出どころの無い基準を作らない）。
 */
class MetricBenchmark
{
    /**
     * 比較に必要な最小の母集団（対象企業を含む社数）。
     * 2社では「どちらが上か」しか言えず、位置として意味を持たないため 3 社から出す。
     */
    public const MIN_POPULATION = 3;

    /**
     * @return array<int, array<string, mixed>> 項目ごとの比較結果（比較できない項目も理由付きで返す）
     */
    public function forCompany(Company $company): array
    {
        return $company->officialMetrics
            ->map(fn (OfficialMetric $m) => $this->compare($company, $m))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null 数値として読めない項目は比較対象にしない（null）
     */
    private function compare(Company $company, OfficialMetric $metric): ?array
    {
        $value = $this->toNumber($metric->value);

        if ($value === null) {
            return null;
        }

        // 同じ項目名・同じ単位の数値だけを母集団にする（万円と円が混ざると比較が壊れる）
        $others = OfficialMetric::query()
            ->where('key', $metric->key)
            ->where(fn ($q) => $metric->unit === null
                ? $q->whereNull('unit')
                : $q->where('unit', $metric->unit))
            ->where('company_id', '!=', $company->id)
            ->with('company:id,name')
            ->get();

        // 同じ企業に同じ項目が複数年ある場合、企業ごとに1件（最新）へ寄せてから並べる
        $values = $this->latestPerCompany($others);
        $population = $values->count() + 1;

        $base = [
            // どの公式数値の行に添えるかを id で対応づける（数値でない項目は落ちるため添字は使えない）
            'metricId' => $metric->id,
            'key' => $metric->key,
            'unit' => $metric->unit,
            'value' => $value,
            'fiscalYear' => $metric->fiscal_year,
            'population' => $population,
        ];

        if ($population < self::MIN_POPULATION) {
            return $base + [
                'comparable' => false,
                // 比較できないことを黙って隠さず、何社あれば出るかを書く
                'reason' => '同じ項目を持つ企業が'.self::MIN_POPULATION.'社に満たないため比較できません（現在'.$population.'社）',
                'rank' => null,
                'median' => null,
                'diff' => null,
                'diffPercent' => null,
            ];
        }

        $all = $values->push($value)->sort()->values();
        $median = $this->median($all);
        // 大きいほど上位（1位 = 母集団で最も大きい）。年収・勤続年数のどちらでも
        // 「大きい方が上」とは限らないため、順位の意味づけは画面側の文言で行わない
        $rank = $all->filter(fn (float $v) => $v > $value)->count() + 1;

        return $base + [
            'comparable' => true,
            'reason' => null,
            'rank' => $rank,
            'median' => $median,
            'diff' => round($value - $median, 2),
            // 中央値が 0 のときは割合を出せない（0 を返さず null で「出せない」を示す）
            'diffPercent' => $median == 0.0 ? null : round(($value - $median) / $median * 100, 1),
        ];
    }

    /**
     * 企業ごとに最新の1件へ寄せた値の一覧。
     *
     * @param  Collection<int, OfficialMetric>  $metrics
     * @return Collection<int, float>
     */
    private function latestPerCompany(Collection $metrics): Collection
    {
        return $metrics
            ->map(fn (OfficialMetric $m) => ['company_id' => $m->company_id, 'value' => $this->toNumber($m->value), 'id' => $m->id])
            ->filter(fn (array $row) => $row['value'] !== null)
            ->sortByDesc('id')
            ->unique('company_id')
            ->map(fn (array $row) => $row['value'])
            ->values();
    }

    /**
     * 数値として読める値だけを float にする。読めない値（「非公開」等）は null。
     * 桁区切りのカンマだけは除去する（表記の揺れであって別の値ではないため）。
     */
    private function toNumber(string $value): ?float
    {
        $normalized = str_replace(',', '', trim($value));

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    /**
     * @param  Collection<int, float>  $sorted  昇順に並んだ値
     */
    private function median(Collection $sorted): float
    {
        $count = $sorted->count();
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $sorted[$middle]
            : round(($sorted[$middle - 1] + $sorted[$middle]) / 2, 2);
    }
}
