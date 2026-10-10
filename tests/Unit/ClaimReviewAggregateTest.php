<?php

namespace Tests\Unit;

use App\Enums\RunStatus;
use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Models\AnalysisRun;
use App\Models\Company;
use App\Models\ReviewItem;
use App\Models\ReviewJudgment;
use App\Services\Ai\ClaimReviewAggregate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 口コミの機械判定（RJ）の主張別集計。件数・文言・出典と、「要確認」を立てる境界を見る。
 */
class ClaimReviewAggregateTest extends TestCase
{
    use RefreshDatabase;

    private AnalysisRun $run;

    private int $sourceId;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['name' => '株式会社テスト']);
        $this->sourceId = $company->sourceDocuments()->create([
            'type' => SourceType::Review,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '口コミ',
            'split_at' => now(),
        ])->id;
        $this->run = $company->analysisRuns()->create(['status' => RunStatus::Done]);
    }

    /**
     * 口コミ 1 件を作り、主張ごとの関係で判定行を保存する。
     *
     * @param  array<int, string>  $relations  claim_index => relation
     */
    private function judge(array $relations, ?AnalysisRun $run = null): ReviewItem
    {
        $run ??= $this->run;
        $body = '口コミ本文'.ReviewItem::count();
        $item = ReviewItem::create([
            'company_id' => $run->company_id,
            'source_document_id' => $this->sourceId,
            'body' => $body,
            'body_hash' => sha1($body),
        ]);

        foreach ($relations as $index => $relation) {
            ReviewJudgment::create([
                'analysis_run_id' => $run->id,
                'review_item_id' => $item->id,
                'claim_index' => $index,
                'claim_hash' => hash('sha256', "主張{$index}"),
                'relation' => $relation,
                'p_contradict' => 0.1,
                'p_support' => 0.1,
                'p_none' => 0.8,
                'confidence' => 0.9,
                'model' => 'jev-1.13.0',
            ]);
        }

        return $item;
    }

    public function test_判定行が無いランは空(): void
    {
        $this->assertSame([], app(ClaimReviewAggregate::class)->forRun($this->run));
    }

    public function test_主張ごとに件数と文言と出典をまとめる(): void
    {
        $a = $this->judge([0 => 'contradict', 1 => 'none']);
        $b = $this->judge([0 => 'support', 1 => 'unsure']);
        $c = $this->judge([0 => 'contradict', 1 => 'support']);
        // 別のランの行は数えない
        $other = $this->run->company->analysisRuns()->create(['status' => RunStatus::Done]);
        $this->judge([0 => 'contradict'], $other);

        $aggregates = app(ClaimReviewAggregate::class)->forRun($this->run);

        $this->assertSame([0, 1], array_keys($aggregates));
        $this->assertSame([
            'total' => 3,
            'contradict' => 2,
            'support' => 1,
            'none' => 0,
            'unsure' => 0,
            'summary' => '口コミ 3 件を機械判定: 矛盾 2・整合 1・言及なし 0・保留 0',
            'contradictItems' => [
                ['itemId' => $a->id, 'sourceId' => $this->sourceId],
                ['itemId' => $c->id, 'sourceId' => $this->sourceId],
            ],
            'supportItems' => [['itemId' => $b->id, 'sourceId' => $this->sourceId]],
        ], $aggregates[0]);
        $this->assertSame('口コミ 3 件を機械判定: 矛盾 0・整合 1・言及なし 1・保留 1', $aggregates[1]['summary']);
        $this->assertSame([], $aggregates[1]['contradictItems']);
    }

    public function test_未知の関係が保存されていたら例外にする(): void
    {
        $this->judge([0 => 'maybe']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('未知の関係 maybe');

        app(ClaimReviewAggregate::class)->forRun($this->run);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int, 3: string|null}>
     */
    public static function flagCases(): array
    {
        return [
            '整合に矛盾の口コミ 3 件で立つ' => ['match', 3, 0, ClaimReviewAggregate::FLAG_CONTRADICT_REVIEWS],
            '整合に矛盾の口コミ 2 件では立たない' => ['match', 2, 0, null],
            '矛盾に整合の口コミ 3 件で立つ' => ['contradict', 0, 3, ClaimReviewAggregate::FLAG_SUPPORT_REVIEWS],
            '矛盾に整合の口コミ 2 件では立たない' => ['contradict', 0, 2, null],
            '整合に整合の口コミが多くても立たない' => ['match', 0, 5, null],
            '判定割れには立てない' => ['split', 5, 5, null],
            '検証不能には立てない' => ['unverifiable', 5, 5, null],
        ];
    }

    #[DataProvider('flagCases')]
    public function test_要確認の境界(string $verdict, int $contradict, int $support, ?string $expected): void
    {
        config(['ai.decision.thresholds.conflict_flag_min_reviews' => 3]);

        $this->assertSame(
            $expected,
            app(ClaimReviewAggregate::class)->flagFor($verdict, ['contradict' => $contradict, 'support' => $support]),
        );
    }

    public function test_要確認のしきい値が無ければ例外にする(): void
    {
        config(['ai.decision.thresholds.conflict_flag_min_reviews' => null]);

        $this->expectException(\RuntimeException::class);

        app(ClaimReviewAggregate::class)->flagFor('match', ['contradict' => 3, 'support' => 0]);
    }
}
