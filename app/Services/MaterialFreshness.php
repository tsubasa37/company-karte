<?php

namespace App\Services;

use App\Models\AnalysisRun;
use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * カルテが今の材料に追いついているか（再分析が必要か）の判定。
 *
 * カルテ画面とダッシュボードの再分析候補で同じ判定を使う（同じ企業が画面ごとに
 * 違う結論になるのを避けるため、比較はここ1箇所で確定させる）。
 *
 * 判定はラン開始時に記録した材料（analysis_runs.materials_json）と現在の材料の差分で行う。
 * 終了時刻と材料の更新時刻の比較では、分析中に投入・更新した材料（完了より古く見える）と、
 * 分析後に削除した材料（比較対象の値が動かない）を取りこぼす。
 *
 * 記録を持たない過去のランは、増えた材料だけを終了時刻で数え、判定できない範囲を
 * unavailableReason に出す（判定できないことを黙って「最新」と見せない）。
 */
class MaterialFreshness
{
    /**
     * ラン開始時に残す材料の記録（種類 => [ID => 更新時刻]）。
     *
     * @return array{sources: array<int, string>, metrics: array<int, string>}
     */
    public function snapshot(Company $company): array
    {
        return [
            'sources' => $this->stamps($company->sourceDocuments),
            'metrics' => $this->stamps($company->officialMetrics),
        ];
    }

    /**
     * このランのカルテと現在の材料の差。
     *
     * @return array{newSources: int, newMetrics: int, removedSources: int, removedMetrics: int, isStale: bool, unavailableReason: string|null}
     */
    public function compare(Company $company, AnalysisRun $run): array
    {
        $recorded = $run->materials_json;

        return is_array($recorded)
            ? $this->bySnapshot($company, $recorded)
            : $this->byFinishedAt($company, $run);
    }

    /**
     * ラン開始時の記録との差分。投入・更新・削除のすべてを拾える。
     *
     * @param  array<string, mixed>  $recorded
     * @return array{newSources: int, newMetrics: int, removedSources: int, removedMetrics: int, isStale: bool, unavailableReason: string|null}
     */
    private function bySnapshot(Company $company, array $recorded): array
    {
        $sources = $this->diff($this->stamps($company->sourceDocuments), $recorded['sources'] ?? []);
        $metrics = $this->diff($this->stamps($company->officialMetrics), $recorded['metrics'] ?? []);

        return $this->result(
            newSources: $sources['changed'],
            newMetrics: $metrics['changed'],
            removedSources: $sources['removed'],
            removedMetrics: $metrics['removed'],
            unavailableReason: null,
        );
    }

    /**
     * 材料の記録が無い過去のラン向け。終了時刻より後に投入・更新された材料だけを数える。
     * 削除と、分析中に差し替わった材料は判定できないため、その旨を必ず添える。
     *
     * @return array{newSources: int, newMetrics: int, removedSources: int, removedMetrics: int, isStale: bool, unavailableReason: string|null}
     */
    private function byFinishedAt(Company $company, AnalysisRun $run): array
    {
        $finishedAt = $run->finished_at;

        if ($finishedAt === null) {
            return $this->result(
                newSources: 0,
                newMetrics: 0,
                removedSources: 0,
                removedMetrics: 0,
                unavailableReason: 'このランには終了時刻が保存されていないため、分析後に増えた材料を判定できません。',
            );
        }

        return $this->result(
            newSources: $company->sourceDocuments->filter(fn ($s) => $s->updated_at?->gt($finishedAt))->count(),
            newMetrics: $company->officialMetrics->filter(fn ($m) => $m->updated_at?->gt($finishedAt))->count(),
            removedSources: 0,
            removedMetrics: 0,
            unavailableReason: 'このランには材料の記録がないため、分析中に差し替えた材料と分析後に削除した材料は判定できません'
                .'（次の分析から記録します）。',
        );
    }

    /**
     * @return array{newSources: int, newMetrics: int, removedSources: int, removedMetrics: int, isStale: bool, unavailableReason: string|null}
     */
    private function result(
        int $newSources,
        int $newMetrics,
        int $removedSources,
        int $removedMetrics,
        ?string $unavailableReason,
    ): array {
        return [
            'newSources' => $newSources,
            'newMetrics' => $newMetrics,
            'removedSources' => $removedSources,
            'removedMetrics' => $removedMetrics,
            'isStale' => ($newSources + $newMetrics + $removedSources + $removedMetrics) > 0,
            'unavailableReason' => $unavailableReason,
        ];
    }

    /**
     * 記録と現在の差。changed は「記録に無いID」と「更新時刻が変わったID」の合計。
     *
     * @param  array<int, string>  $current
     * @param  array<int, string>  $recorded
     * @return array{changed: int, removed: int}
     */
    private function diff(array $current, array $recorded): array
    {
        return [
            'changed' => count(array_diff_assoc($current, $recorded)),
            'removed' => count(array_diff_key($recorded, $current)),
        ];
    }

    /**
     * 材料1種類ぶんの「ID => 更新時刻」。記録側と同じ作り方をして初めて差分が取れるため、
     * 記録と判定の両方でこのメソッドだけを使う。
     *
     * @param  Collection<int, Model>  $models
     * @return array<int, string>
     */
    private function stamps(Collection $models): array
    {
        return $models
            ->mapWithKeys(fn ($m) => [(int) $m->getKey() => (string) $m->updated_at?->toDateTimeString()])
            ->all();
    }
}
