<?php

namespace App\Actions;

use App\Enums\AnalysisStep;
use App\Enums\CompanyStatus;
use App\Enums\SourceType;
use App\Models\AnalysisRun;
use App\Models\Company;
use App\Models\OfficialMetric;
use App\Models\Profile;
use App\Services\Ai\VerdictConsensus;
use App\Services\SalaryRangeSignal;

/**
 * 複数社比較ビューの行を組み立てる。各社の最新 done ランから、おすすめ度・
 * 必須/歓迎条件の充足・矛盾件数・労働条件・オファー差額を集計する。
 * 自分用途で企業数が限られるため、社ごとの最新ラン取得は素直に実行する。
 */
class BuildCompareRowsAction
{
    /** おすすめ度の並び順（高い順、未分析は最後） */
    private const ORDER_SQL = "CASE recommendation WHEN 'high' THEN 0 WHEN 'mid' THEN 1 WHEN 'low' THEN 2 WHEN 'hold' THEN 3 ELSE 4 END";

    /** 公式データ（有報/手入力）で平均年収を示す指標キー */
    private const AVG_SALARY_KEY = '平均年間給与';

    /** 労働条件(conditions_json)の抽出材料になるソース種別（ExtractConditionsJob と同一） */
    private const CONDITION_SOURCE_TYPES = [SourceType::JobPosting, SourceType::Other];

    public function __construct(private SalaryRangeSignal $salaryRange) {}

    /**
     * 比較ページの props 一式を組み立てる。
     * 既定は「分析済みのみ」（未分析の空行でノイズにしない）。ids 指定時はその企業だけを
     * 未分析でも表示する（一覧でユーザーが明示的に選んだため）。
     *
     * @param  array<int, int>|null  $ids  一覧のチェックボックスで選ばれた企業ID（未指定は null）
     * @param  bool  $showAll  未分析も含めて全社表示するか
     * @param  CompanyStatus|null  $status  選考ステータスでの絞り込み（未指定は全ステータス）
     * @return array<string, mixed>
     */
    public function execute(?array $ids = null, bool $showAll = false, ?CompanyStatus $status = null): array
    {
        // オファー年収との差はPHPで確定計算する（基準は設定の現年収）
        $currentSalary = Profile::first()?->current_annual_salary;

        $rows = Company::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            // 平均年収・最後に完了したラン（とそのステップ）を社ごとに引くと N+1 になるため先読みする
            ->with(['officialMetrics', 'latestDoneRun.steps'])
            // 「条件が未抽出」と「求人票に記載が無い」を画面で描き分けるための材料の有無
            ->withCount(['sourceDocuments as condition_source_count' => fn ($q) => $q->whereIn(
                'type',
                array_map(fn (SourceType $t) => $t->value, self::CONDITION_SOURCE_TYPES),
            )])
            ->orderByRaw(self::ORDER_SQL)
            ->latest('updated_at')
            ->get()
            ->map(fn (Company $c) => $this->row($c, $currentSalary));

        $visible = ($ids !== null || $showAll)
            ? $rows
            : $rows->filter(fn (array $r) => $r['analyzed'])->values();

        return [
            'rows' => $visible->values()->all(),
            // 既定表示で畳んだ未分析社数（トグルのラベルに使う）
            'hiddenCount' => $rows->count() - $visible->count(),
            'showAll' => $showAll,
            // 存在しないIDが混ざっても空行は作らないため、実際に取得できた社数を返す
            'selectedCount' => $ids !== null ? $rows->count() : null,
            // null = 現年収未設定。画面は差額を出せない理由を明示する（黙って空欄にしない）
            'currentSalary' => $currentSalary,
            'status' => $status?->value,
            'statusOptions' => CompanyStatus::options(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Company $c, ?int $currentSalary): array
    {
        // execute() が先読みした「最後に完了したラン」（無ければ null）
        $run = $c->latestDoneRun;

        $p5 = $this->stepOutput($run, AnalysisStep::P5);
        $verdicts = $this->stepOutput($run, AnalysisStep::P2)['verdicts'] ?? [];
        $mustChecks = $p5['must_checks'] ?? [];
        $wantChecks = $p5['want_checks'] ?? [];
        // ng_checks 導入(2026-07)以前のランにはキー自体が無い。空配列（NG条件0件で確認済み）と
        // 混ぜると「未保存」が「該当なし」に見えるため、保存の有無を別フラグで画面へ渡す
        $ngChecks = $p5['ng_checks'] ?? [];
        $ngSaved = array_key_exists('ng_checks', $p5);
        $offer = $c->offer_json['annual_salary'] ?? null;
        $conditions = $c->conditions_json;

        return [
            'id' => $c->id,
            'name' => $c->name,
            'status' => $c->status->value,
            'statusLabel' => $c->status->label(),
            'recommendation' => $c->recommendation?->value,
            'recommendationLabel' => $c->recommendation?->label(),
            // おすすめ度の判定理由・懸念。キーが無い旧ランは null（画面は「未保存」と明示する）
            'recommendationReason' => $this->optionalText($p5, 'recommendation_reason'),
            'concern' => $this->optionalText($p5, 'concern'),
            'analyzed' => $run !== null,
            'analyzedAt' => $run?->finished_at?->format('Y-m-d'),
            'mustMet' => $this->countStatus($mustChecks, 'met'),
            'mustUnmet' => $this->countStatus($mustChecks, 'unmet'),
            'mustTotal' => count($mustChecks),
            'wantMet' => $this->countStatus($wantChecks, 'met'),
            'wantTotal' => count($wantChecks),
            'ngHit' => $this->countStatus($ngChecks, 'hit'),
            'ngUnknown' => $this->countStatus($ngChecks, 'unknown'),
            'ngTotal' => count($ngChecks),
            'ngSaved' => $ngSaved,
            'contradicts' => $this->countVerdict($verdicts, 'contradict'),
            // 判定者の意見が分かれた主張。材料不足（unverifiables）と混ぜると
            // 「材料を足すべき会社」と「面接で聞くべき会社」が同じ数字に見えてしまう
            'splits' => $this->countVerdict($verdicts, VerdictConsensus::SPLIT),
            'unverifiables' => $this->countVerdict($verdicts, 'unverifiable'),
            // 求人票からAI抽出した労働条件（未抽出は null）
            'conditions' => $conditions,
            // 条件が null のとき「材料が無い」のか「抽出が済んでいない/失敗した」のかを画面で描き分ける
            'hasConditionSource' => $c->condition_source_count > 0,
            // 年収レンジ幅の構造シグナル（倍率・差額は PHP で確定計算）。条件未抽出なら null
            'salaryRange' => $conditions === null
                ? null
                : $this->salaryRange->evaluate($conditions['salary_min'] ?? null, $conditions['salary_max'] ?? null),
            'avgSalary' => $this->avgSalary($c),
            'offerSalary' => $offer,
            'offerDelta' => ($offer !== null && $currentSalary !== null) ? $offer - $currentSalary : null,
            // 差額だけでは実質が読めないオファー（固定残業込み・賞与変動）への注記。無ければ null
            'offerCaveat' => $this->offerCaveat($c->offer_json ?? []),
        ];
    }

    /**
     * オファー額の単純差額に添える注記。固定残業込みや賞与依存のオファーを
     * 「+50万」だけで良条件と読ませないための確認喚起で、判定はしない。
     *
     * @param  array<string, mixed>  $offerJson
     */
    private function offerCaveat(array $offerJson): ?string
    {
        $parts = [];

        $hours = $offerJson['fixed_overtime_hours'] ?? null;
        if ($hours !== null && (int) $hours > 0) {
            $parts[] = "固定残業{$hours}h込み";
        }

        $bonus = $offerJson['bonus_months'] ?? null;
        if ($bonus !== null && (float) $bonus > 0) {
            $parts[] = "賞与{$bonus}ヶ月分を含む";
        }

        if ($parts === []) {
            return null;
        }

        return implode(' / ', $parts).' — 現年収との単純比較はできません';
    }

    /**
     * 公式データの平均年間給与（未登録は null）。
     * 同じキーが複数年ある場合は会計年度の大きいもの（最新）を採る。
     *
     * @return array{value: string, unit: string|null, fiscalYear: string|null}|null
     */
    private function avgSalary(Company $c): ?array
    {
        $metric = $c->officialMetrics
            ->where('key', self::AVG_SALARY_KEY)
            ->sortByDesc('fiscal_year')
            ->first();

        return $metric instanceof OfficialMetric
            ? ['value' => $metric->value, 'unit' => $metric->unit, 'fiscalYear' => $metric->fiscal_year]
            : null;
    }

    /**
     * キーが存在しない旧ランと、キーはあるが空文字の場合を区別する
     * （前者は null で「未保存」、後者は空文字で「記載なし」を画面が出す）。
     *
     * @param  array<string, mixed>  $output
     */
    private function optionalText(array $output, string $key): ?string
    {
        return array_key_exists($key, $output) ? (string) $output[$key] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function stepOutput(?AnalysisRun $run, AnalysisStep $step): array
    {
        $output = $run?->steps->firstWhere('step', $step->value)?->output_json;

        return is_array($output) ? $output : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     */
    private function countStatus(array $checks, string $status): int
    {
        return count(array_filter($checks, fn ($c) => ($c['status'] ?? null) === $status));
    }

    /**
     * @param  array<int, array<string, mixed>>  $verdicts
     */
    private function countVerdict(array $verdicts, string $verdict): int
    {
        return count(array_filter($verdicts, fn ($v) => ($v['verdict'] ?? null) === $verdict));
    }
}
