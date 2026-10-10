<?php

namespace App\Services\Ai;

use App\Models\AnalysisRun;
use App\Models\ReviewJudgment;

/**
 * 口コミの機械判定（RJ）の行を、主張（claim_index）ごとの件数と表示文言に集計する。
 *
 * 件数・文言・「要確認」の判定は PHP が確定し、画面は描画だけにする。
 * 確率は集計に含めない（画面に確率の生数値を出さない方針）。
 */
class ClaimReviewAggregate
{
    /** LLM が「整合」とした主張に、矛盾とされた口コミがしきい値以上ある */
    public const FLAG_CONTRADICT_REVIEWS = 'contradict_reviews';

    /** LLM が「矛盾」とした主張に、整合とされた口コミがしきい値以上ある */
    public const FLAG_SUPPORT_REVIEWS = 'support_reviews';

    private const RELATIONS = [
        ReviewJudgmentRunner::RELATION_CONTRADICT,
        ReviewJudgmentRunner::RELATION_SUPPORT,
        ReviewJudgmentRunner::RELATION_NONE,
        ReviewJudgmentRunner::RELATION_UNSURE,
    ];

    /**
     * ランの判定行を主張ごとに集計する。RJ の行が無いランは []。
     *
     * @return array<int, array{total: int, contradict: int, support: int, none: int, unsure: int, summary: string, contradictItems: list<array{itemId: int, sourceId: int}>, supportItems: list<array{itemId: int, sourceId: int}>}>
     */
    public function forRun(AnalysisRun $run): array
    {
        // 口コミの出典（source_documents.id）へ辿るため、口コミの行を 1 回の結合で引く（行ごとに引かない）
        $rows = ReviewJudgment::query()
            ->join('review_items', 'review_items.id', '=', 'review_judgments.review_item_id')
            ->where('review_judgments.analysis_run_id', $run->id)
            ->orderBy('review_judgments.claim_index')
            ->orderBy('review_judgments.review_item_id')
            ->get([
                'review_judgments.claim_index',
                'review_judgments.relation',
                'review_judgments.review_item_id',
                'review_items.source_document_id',
            ]);

        $aggregates = [];

        foreach ($rows as $row) {
            $index = (int) $row->claim_index;
            $relation = (string) $row->relation;

            if (! in_array($relation, self::RELATIONS, true)) {
                throw new \LogicException("口コミの機械判定に未知の関係 {$relation} が保存されています（判定行 run={$run->id}）");
            }

            $aggregates[$index] ??= array_fill_keys(self::RELATIONS, 0) + ['contradictItems' => [], 'supportItems' => []];
            $aggregates[$index][$relation]++;

            $item = ['itemId' => (int) $row->review_item_id, 'sourceId' => (int) $row->source_document_id];
            match ($relation) {
                ReviewJudgmentRunner::RELATION_CONTRADICT => $aggregates[$index]['contradictItems'][] = $item,
                ReviewJudgmentRunner::RELATION_SUPPORT => $aggregates[$index]['supportItems'][] = $item,
                default => null,
            };
        }

        return array_map(fn (array $a) => $this->finish($a), $aggregates);
    }

    /**
     * LLM の判定と逆向きの口コミがしきい値以上あれば「要確認」の種別を返す。
     * 判定割れ・検証不能は LLM 側が向きを決めていないので立てない。
     *
     * @param  array{contradict: int, support: int}  $aggregate
     */
    public function flagFor(string $verdict, array $aggregate): ?string
    {
        $min = $this->conflictMin();

        return match (true) {
            $verdict === 'match' && $aggregate['contradict'] >= $min => self::FLAG_CONTRADICT_REVIEWS,
            $verdict === 'contradict' && $aggregate['support'] >= $min => self::FLAG_SUPPORT_REVIEWS,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private function finish(array $a): array
    {
        $total = $a['contradict'] + $a['support'] + $a['none'] + $a['unsure'];

        return [
            'total' => $total,
            'contradict' => $a['contradict'],
            'support' => $a['support'],
            'none' => $a['none'],
            'unsure' => $a['unsure'],
            // 表示文言は画面で組み立てず、ここで確定する（VerdictConsensus の summary と同じ流儀）
            'summary' => "口コミ {$total} 件を機械判定: 矛盾 {$a['contradict']}・整合 {$a['support']}・言及なし {$a['none']}・保留 {$a['unsure']}",
            'contradictItems' => $a['contradictItems'],
            'supportItems' => $a['supportItems'],
        ];
    }

    private function conflictMin(): int
    {
        $value = config('ai.decision.thresholds.conflict_flag_min_reviews');

        if (! is_numeric($value) || (int) $value <= 0) {
            throw new \RuntimeException('config/ai.php の decision.thresholds.conflict_flag_min_reviews が設定されていません');
        }

        return (int) $value;
    }
}
