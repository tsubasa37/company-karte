<?php

namespace App\Services\Ai;

use App\Enums\AnalysisStep;

/**
 * P1〜P6 各ステップの codex 構造化出力スキーマ。
 * OpenAI structured output 準拠: root は object / 全 object に additionalProperties:false /
 * 全 property を required に列挙 / nullable は type:[..,"null"]。
 */
class AnalysisSchemas
{
    /**
     * @return array<string, mixed>
     */
    public function for(AnalysisStep $step): array
    {
        return match ($step) {
            AnalysisStep::P1 => $this->p1(),
            AnalysisStep::P2 => $this->p2(),
            AnalysisStep::P3 => $this->p3(),
            AnalysisStep::P4 => $this->p4(),
            AnalysisStep::P5 => $this->p5(),
            AnalysisStep::P6 => $this->p6(),
            AnalysisStep::RJ => throw new \LogicException('rj は JSON スキーマを持たない（判定 API）'),
        };
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function obj(array $properties): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array_keys($properties),
            'properties' => $properties,
        ];
    }

    /**
     * @param  array<string, mixed>  $itemProperties
     * @return array<string, mixed>
     */
    private function arrayOfObjects(array $itemProperties): array
    {
        return ['type' => 'array', 'items' => $this->obj($itemProperties)];
    }

    private function str(): array
    {
        return ['type' => 'string'];
    }

    private function nullableStr(): array
    {
        return ['type' => ['string', 'null']];
    }

    /** 原文に記載が無ければ null になる数値（推定・計算はさせない項目に使う） */
    private function nullableInt(): array
    {
        return ['type' => ['integer', 'null']];
    }

    private function arrayOfInt(): array
    {
        return ['type' => 'array', 'items' => ['type' => 'integer']];
    }

    /** @param array<int,string> $values */
    private function enum(array $values): array
    {
        return ['type' => 'string', 'enum' => $values];
    }

    private function arrayOfStr(): array
    {
        return ['type' => 'array', 'items' => ['type' => 'string']];
    }

    private function p1(): array
    {
        return $this->obj([
            'claims' => $this->arrayOfObjects([
                'claim' => $this->str(),
                'quote' => $this->str(),
                'category' => $this->enum([
                    'salary', 'overtime', 'holiday', 'remote', 'culture',
                    'turnover', 'growth', 'business', 'tech', 'other',
                ]),
                'verifiable' => ['type' => 'boolean'],
                // 転職判断への影響度。high(待遇・労働時間・雇用条件) / low(事業内容・所在地などの事実)
                'importance' => $this->enum(['high', 'mid', 'low']),
            ]),
        ]);
    }

    private function p2(): array
    {
        return $this->obj([
            'verdicts' => $this->arrayOfObjects([
                'claim' => $this->str(),
                // 対応する P1 主張の添字（claims 配列の 0 始まり）。P1 の重要度を判定行に引き当てるために使う。
                // 主張テキストの一致で引き当てる方式は、P1（要約）と P2（原文引用優先）の文体差で全滅した実績があるため使わない
                'claim_index' => ['type' => ['integer', 'null']],
                'source' => $this->str(),
                'checked_against' => $this->str(),
                // 突き合わせた材料の見出し（【求人票 #12 / host】）にある ID。画面から材料へ辿るために使う
                'source_ids' => $this->arrayOfInt(),
                // AI に出させる語彙はこの3値のみ。複数人で裏取りしたときは、保存前に
                // VerdictConsensus が意見の割れを 'split' に確定するため、保存済みの
                // output_json にはこの enum に無い 'split' が入りうる（PHP が付ける値）
                'verdict' => $this->enum(['match', 'contradict', 'unverifiable']),
                'rationale' => $this->str(),
            ]),
        ]);
    }

    private function p3(): array
    {
        return $this->obj([
            'rhetoric_flags' => $this->arrayOfObjects([
                'phrase' => $this->str(),
                'quote' => $this->str(),
                'concern' => $this->str(),
            ]),
            'concreteness' => $this->obj([
                'level' => $this->enum(['low', 'mid', 'high']),
                'reason' => $this->str(),
            ]),
            // 実質給与は AI に計算させず「原文に書かれた数値の抽出」だけをさせる。
            // 基本給・実質時給は SalaryBreakdownCalculator が PHP で確定計算し computed キーに入れる。
            'salary_breakdown' => $this->obj([
                'has_fixed_overtime' => ['type' => 'boolean'],
                'fixed_overtime_hours' => $this->nullableInt(),
                'fixed_overtime_amount_yen' => $this->nullableInt(),
                'monthly_salary_min_yen' => $this->nullableInt(),
                'monthly_salary_max_yen' => $this->nullableInt(),
                'note' => $this->str(),
            ]),
        ]);
    }

    private function p4(): array
    {
        return $this->obj([
            'material_summary' => $this->str(),
            'strong_points' => $this->arrayOfStr(),
            'weak_or_extreme' => $this->arrayOfStr(),
            'split_points' => $this->arrayOfStr(),
            // 古い口コミと最近の口コミで様子が変わった点
            'time_changes' => $this->arrayOfStr(),
            'note' => $this->str(),
        ]);
    }

    private function p5(): array
    {
        return $this->obj([
            'must_checks' => $this->arrayOfObjects([
                'condition' => $this->str(),
                'status' => $this->enum(['met', 'unmet', 'unknown']),
                'evidence' => $this->str(),
            ]),
            'want_checks' => $this->arrayOfObjects([
                'condition' => $this->str(),
                'status' => $this->enum(['met', 'unmet', 'unknown']),
                'evidence' => $this->str(),
            ]),
            // NG条件（絶対に避けたい条件）の該当判定
            'ng_checks' => $this->arrayOfObjects([
                'condition' => $this->str(),
                'status' => $this->enum(['hit', 'clear', 'unknown']),
                'evidence' => $this->str(),
            ]),
            'appeal' => $this->str(),
            'concern' => $this->str(),
            'summary_lines' => $this->arrayOfStr(),
        ]);
    }

    private function p6(): array
    {
        return $this->obj([
            'questions' => $this->arrayOfObjects([
                'priority' => $this->enum(['high', 'mid', 'low']),
                'question' => $this->str(),
                'targets' => $this->str(),
                'ask_to' => $this->enum(['hr', 'engineer', 'exec', 'any']),
                'casual_version' => $this->str(),
            ]),
        ]);
    }
}
