<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Enums\SourceType;
use App\Models\Company;

/**
 * 「この企業の判定を固めるのに、あと何の材料が要るか」を機械的に出す。
 *
 * AI は使わない（投入済みの材料を数えるだけ）。おすすめ度が「― 判定保留」になる理由を
 * 企業ごとに具体化し、次に取るべきソースを提示するのが目的。
 * 材料の多寡で企業を評価するものではない（点数化・順位付けには使わない）。
 */
class MaterialCoverage
{
    /**
     * 口コミの最低件数。1〜2件では退職者バイアスの補正（P4）が効かず、
     * 単発の極端な意見がそのまま判定材料になるため。
     */
    public const MIN_REVIEW_ITEMS = 3;

    /** 材料が揃っている（不足なし） */
    public const LEVEL_SUFFICIENT = 'sufficient';

    /** 判定はできるが、裏取りの材料が足りない */
    public const LEVEL_PARTIAL = 'partial';

    /** 求人票が無く、おすすめ度が判定保留から動かない */
    public const LEVEL_INSUFFICIENT = 'insufficient';

    /**
     * 1行表示に出す不足の件数。項目は判定への効きが強い順（求人票 → 口コミ → …）に並ぶので、
     * 先頭から詰めれば「まず何をすべきか」が残る。
     */
    private const SUMMARY_LIMIT = 2;

    /** 求人票として扱うソース種別（分析側の JOBPOSTING_TYPES と同じ） */
    private const JOBPOSTING_TYPES = [SourceType::JobPosting, SourceType::Other];

    /** 面接メモを求める選考段階（応募前の企業に面接メモの不足は出さない） */
    private const INTERVIEW_EXPECTED = [CompanyStatus::Interview, CompanyStatus::Offer];

    /**
     * @return array<string, mixed>
     */
    public function evaluate(Company $company): array
    {
        $items = [
            $this->jobPosting($company),
            $this->reviews($company),
            $this->officialMetrics($company),
        ];

        $interview = $this->interviewNote($company);
        // 応募前の企業に「面接メモが無い」を出しても、やることが無い項目が並ぶだけ
        if ($interview !== null) {
            $items[] = $interview;
        }

        $missing = array_values(array_filter($items, fn (array $i) => ! $i['satisfied']));

        return [
            'items' => $items,
            'missing' => $missing,
            'missingCount' => count($missing),
            'level' => $this->level($items),
        ];
    }

    /**
     * 不足を1行にまとめた文言（ダッシュボードの1行表示用）。不足が無ければ null。
     * 一覧の可読性を優先して先頭 SUMMARY_LIMIT 件までにし、残りは件数だけ添える
     * （全件は企業ページの「材料の充足」で出す）。
     */
    public function summaryLine(Company $company): ?string
    {
        $missing = $this->evaluate($company)['missing'];

        if ($missing === []) {
            return null;
        }

        $shown = array_slice($missing, 0, self::SUMMARY_LIMIT);
        $line = implode(' / ', array_map(fn (array $i) => $i['hint'], $shown));
        $rest = count($missing) - count($shown);

        return $rest > 0 ? "{$line} ／ 他{$rest}件" : $line;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function level(array $items): string
    {
        foreach ($items as $item) {
            // 求人票の不足だけは判定そのものが成立しない（おすすめ度が判定保留のまま）
            if ($item['key'] === 'jobposting' && ! $item['satisfied']) {
                return self::LEVEL_INSUFFICIENT;
            }
        }

        $missing = array_filter($items, fn (array $i) => ! $i['satisfied']);

        return $missing === [] ? self::LEVEL_SUFFICIENT : self::LEVEL_PARTIAL;
    }

    /**
     * @return array<string, mixed>
     */
    private function jobPosting(Company $company): array
    {
        $count = $company->sourceDocuments
            ->filter(fn ($s) => in_array($s->type, self::JOBPOSTING_TYPES, true))
            ->count();

        return [
            'key' => 'jobposting',
            'label' => '求人票',
            'satisfied' => $count > 0,
            'detail' => $count > 0 ? "{$count}件" : '未投入',
            'hint' => '求人票を投入する（無いとおすすめ度は判定保留のまま）',
            // 判定そのものの成否に関わる
            'impact' => 'high',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviews(Company $company): array
    {
        $count = $company->reviewItems->count();
        $enough = $count >= self::MIN_REVIEW_ITEMS;

        return [
            'key' => 'review',
            'label' => '口コミ',
            'satisfied' => $enough,
            'detail' => $count > 0 ? "{$count}件（分割済み）" : '未投入',
            'hint' => $count > 0
                ? '口コミをあと'.(self::MIN_REVIEW_ITEMS - $count).'件以上足す（単発の意見で判定しないため）'
                : '口コミを投入する（求人票の主張の裏取りに使う）',
            'impact' => 'high',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function officialMetrics(Company $company): array
    {
        $count = $company->officialMetrics->count();

        return [
            'key' => 'metric',
            'label' => '公式数値',
            'satisfied' => $count > 0,
            'detail' => $count > 0 ? "{$count}件" : '未投入',
            'hint' => 'EDINET・gBizINFO の取得、またはしょくばらぼの数値を手入力する',
            // 無くても判定は出るが、年収・離職率の裏取りが口コミだけになる
            'impact' => 'mid',
        ];
    }

    /**
     * 面接メモ。応募前の企業には項目自体を出さない（不足として数えない）。
     *
     * @return array<string, mixed>|null
     */
    private function interviewNote(Company $company): ?array
    {
        if (! in_array($company->status, self::INTERVIEW_EXPECTED, true)) {
            return null;
        }

        $count = $company->sourceDocuments
            ->filter(fn ($s) => $s->type === SourceType::Interview)
            ->count();

        return [
            'key' => 'interview',
            'label' => '面接メモ',
            'satisfied' => $count > 0,
            'detail' => $count > 0 ? "{$count}件" : '未投入',
            'hint' => '面接での回答を面接メモに残す（一次情報として再分析に効く）',
            'impact' => 'mid',
        ];
    }
}
