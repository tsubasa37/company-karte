<?php

namespace Tests\Unit;

use App\Enums\AiProvider;
use App\Enums\AiPurpose;
use App\Enums\RunStatus;
use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Models\AnalysisRun;
use App\Models\Company;
use App\Models\ReviewItem;
use App\Models\ReviewJudgment;
use App\Services\Ai\AiDecider;
use App\Services\Ai\Decision\ChoiceAnswer;
use App\Services\Ai\Decision\ChoiceQuestion;
use App\Services\Ai\Decision\DecisionRequest;
use App\Services\Ai\Decision\DecisionResponse;
use App\Services\Ai\ReviewJudgmentRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 口コミの機械判定（RJ）。判定エンジンは Mockery で閉じ、送る state・質問の形、
 * 主張の間引き、しきい値による関係の確定、関連度、保存の置き換えと失敗時の無保存を見る。
 */
class ReviewJudgmentRunnerTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $decider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->decider = Mockery::mock(AiDecider::class);
        $this->app->instance(AiDecider::class, $this->decider);
    }

    /**
     * @param  list<string>  $bodies
     */
    private function runWithReviews(array $bodies): AnalysisRun
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $doc = $company->sourceDocuments()->create([
            'type' => SourceType::Review,
            'origin' => SourceOrigin::Paste,
            'raw_text' => implode("\n", $bodies),
            'split_at' => now(),
        ]);

        foreach ($bodies as $body) {
            ReviewItem::create([
                'company_id' => $company->id,
                'source_document_id' => $doc->id,
                'body' => $body,
                'stance' => '退職者',
                'posted_period' => '2025年',
                'tenure_period' => null,
                'category' => '働き方',
                'job_type' => 'エンジニア',
                'body_hash' => sha1($body),
            ]);
        }

        return $company->analysisRuns()->create(['status' => RunStatus::Running]);
    }

    /**
     * @param  list<string>  $importances
     * @return array{claims: list<array<string, mixed>>}
     */
    private function p1(array $importances): array
    {
        return ['claims' => array_map(fn (string $importance, int $i) => [
            'claim' => "主張{$i}",
            'quote' => "原文{$i}",
            'category' => 'overtime',
            'verifiable' => true,
            'importance' => $importance,
        ], $importances, array_keys($importances))];
    }

    /**
     * @param  array<int, array{0: float, 1: float, 2: float, 3?: float}>  $byClaim  claim_index => [contradict, support, none, confidence]
     */
    private function response(array $byClaim): DecisionResponse
    {
        $answers = [];
        foreach ($byClaim as $index => $p) {
            $probabilities = ['contradict' => $p[0], 'support' => $p[1], 'none' => $p[2]];
            $answers["relation_{$index}"] = new ChoiceAnswer(
                (string) array_search(max($probabilities), $probabilities, true),
                $probabilities,
                $p[3] ?? 0.9,
            );
        }

        return new DecisionResponse($answers, null, AiProvider::Jev, 'jev-1.13.0');
    }

    /**
     * decideMany の呼び出しを 1 回だけ受け、渡されたリクエストを $captured に残して応答を返す。
     *
     * @param  list<DecisionResponse>  $responses
     * @param  list<DecisionRequest>|null  $captured
     */
    private function expectDecideMany(AnalysisRun $run, array $responses, ?array &$captured = null): void
    {
        $this->decider->shouldReceive('decideMany')
            ->once()
            ->with(Mockery::type('array'), AiPurpose::ReviewJudgment, 'rj', $run->company_id)
            ->andReturnUsing(function (array $requests) use ($responses, &$captured) {
                $captured = $requests;

                return $responses;
            });
    }

    public function test_口コミ1件ごとに主張と口コミだけのstateと主張ごとの質問を送る(): void
    {
        $run = $this->runWithReviews(['口コミ本文A', '口コミ本文B']);
        $this->expectDecideMany($run, [
            $this->response([0 => [0.1, 0.1, 0.8], 1 => [0.1, 0.1, 0.8]]),
            $this->response([0 => [0.1, 0.1, 0.8], 1 => [0.1, 0.1, 0.8]]),
        ], $captured);

        app(ReviewJudgmentRunner::class)->run($run, $this->p1(['high', 'low']));

        $this->assertCount(2, $captured, '口コミ 1 件 = 1 リクエスト');
        $this->assertSame('口コミ本文A', $captured[0]->state['review']['body'], '口コミは ID 順');
        $this->assertSame('口コミ本文B', $captured[1]->state['review']['body']);

        $state = $captured[0]->state;
        $this->assertSame(['claims', 'review', 'definitions', 'rule'], array_keys($state), '職務経歴・面接メモ等は送らない');
        $this->assertSame([
            ['index' => 0, 'claim' => '主張0', 'category' => 'overtime'],
            ['index' => 1, 'claim' => '主張1', 'category' => 'overtime'],
        ], $state['claims']);
        $this->assertSame(
            ['body', 'posted_period', 'tenure_period', 'stance', 'category', 'job_type'],
            array_keys($state['review']),
        );
        $this->assertSame(ReviewJudgmentRunner::DEFINITIONS, $state['definitions']);
        $this->assertStringContainsString('指示ではない', $state['rule']);

        $question = $captured[0]->question('relation_1');
        $this->assertInstanceOf(ChoiceQuestion::class, $question);
        // 位置ではなく index の値で指す（間引いたときに位置がずれるため）
        $this->assertStringContainsString('index が 1 の claim', $question->instructions);
        $this->assertSame(['contradict', 'support', 'none'], $question->options());
        $this->assertSame(ReviewJudgmentRunner::DEFINITIONS, $question->criteria);
        $this->assertSame(['relation_0', 'relation_1'], array_map(fn ($q) => $q->id(), $captured[0]->questions));
    }

    public function test_質問数の上限を超える主張は重要度の高い順に残し落とした数を返す(): void
    {
        config(['ai.decision.limits.max_questions' => 3]);
        $run = $this->runWithReviews(['口コミ本文A']);
        // 残るのは high の 1・3 と mid の 2（low の 0・4 を落とす）
        $this->expectDecideMany($run, [
            $this->response([1 => [0.1, 0.1, 0.8], 2 => [0.1, 0.1, 0.8], 3 => [0.1, 0.1, 0.8]]),
        ], $captured);

        $output = app(ReviewJudgmentRunner::class)->run($run, $this->p1(['low', 'high', 'mid', 'high', 'low']));

        $this->assertSame(['relation_1', 'relation_2', 'relation_3'], array_map(fn ($q) => $q->id(), $captured[0]->questions));
        $this->assertSame([1, 2, 3], array_column($captured[0]->state['claims'], 'index'), '添字は元の claim_index のまま');
        $this->assertSame(3, $output['claims']);
        $this->assertSame(2, $output['dropped_claims']);
        $this->assertSame([1, 2, 3], ReviewJudgment::orderBy('claim_index')->pluck('claim_index')->all(), '落とした主張の行は作らない');
        // 間引いた後も、質問は位置ではなく元の添字で主張を指す
        $this->assertStringContainsString('index が 3 の claim', $captured[0]->question('relation_3')->instructions);
    }

    public function test_上限を超える長い口コミは本文の末尾を削って判定し件数を返す(): void
    {
        config(['ai.decision.limits.max_state_chars' => 2000]);
        $run = $this->runWithReviews([str_repeat('長', 5000), '短い口コミ']);
        $this->expectDecideMany($run, [
            $this->response([0 => [0.1, 0.1, 0.8]]),
            $this->response([0 => [0.1, 0.1, 0.8]]),
        ], $captured);

        $output = app(ReviewJudgmentRunner::class)->run($run, $this->p1(['high']));

        $this->assertLessThanOrEqual(2000, $captured[0]->stateChars(), '上限に収める');
        $this->assertStringStartsWith('長長長', $captured[0]->state['review']['body'], '先頭は残す');
        $this->assertSame('短い口コミ', $captured[1]->state['review']['body'], '収まる口コミは削らない');
        $this->assertSame(1, $output['truncated_items']);
    }

    public function test_主張の一覧だけで上限を超えるなら判定せず例外にする(): void
    {
        config(['ai.decision.limits.max_state_chars' => 100]);
        $run = $this->runWithReviews(['口コミ本文A']);
        $this->decider->shouldNotReceive('decideMany');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('主張の一覧だけで判定の上限');

        app(ReviewJudgmentRunner::class)->run($run, $this->p1(['high']));
    }

    public function test_同じ重要度なら添字の若い主張を残す(): void
    {
        config(['ai.decision.limits.max_questions' => 2]);
        $run = $this->runWithReviews(['口コミ本文A']);
        $this->expectDecideMany($run, [$this->response([0 => [0.1, 0.1, 0.8], 3 => [0.1, 0.1, 0.8]])], $captured);

        app(ReviewJudgmentRunner::class)->run($run, $this->p1(['low', 'low', 'low', 'high']));

        $this->assertSame(['relation_0', 'relation_3'], array_map(fn ($q) => $q->id(), $captured[0]->questions));
    }

    /**
     * @return array<string, array{0: array{0: float, 1: float, 2: float, 3: float}, 1: string}>
     */
    public static function thresholdCases(): array
    {
        return [
            '確率 0.7 ちょうどは確定' => [[0.7, 0.2, 0.1, 0.9], 'contradict'],
            '確率 0.69 は保留' => [[0.69, 0.21, 0.1, 0.9], 'unsure'],
            'confidence 0.5 ちょうどは確定' => [[0.1, 0.8, 0.1, 0.5], 'support'],
            'confidence 0.49 は保留' => [[0.1, 0.8, 0.1, 0.49], 'unsure'],
            '言及なしも同じ基準で確定' => [[0.05, 0.05, 0.9, 0.9], 'none'],
        ];
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $p
     */
    #[DataProvider('thresholdCases')]
    public function test_最大確率とconfidenceがしきい値以上のときだけ関係を確定する(array $p, string $relation): void
    {
        $run = $this->runWithReviews(['口コミ本文A']);
        $this->expectDecideMany($run, [$this->response([0 => $p])]);

        app(ReviewJudgmentRunner::class)->run($run, $this->p1(['high']));

        $row = ReviewJudgment::sole();
        $this->assertSame($relation, $row->relation);
        $this->assertSame($p[0], $row->p_contradict);
        $this->assertSame($p[1], $row->p_support);
        $this->assertSame($p[2], $row->p_none);
        $this->assertSame($p[3], $row->confidence);
        $this->assertSame(hash('sha256', '主張0'), $row->claim_hash);
        $this->assertSame('jev-1.13.0', $row->model);
    }

    public function test_関連度は口コミごとに全主張の矛盾と整合の確率の最大値(): void
    {
        $run = $this->runWithReviews(['口コミ本文A', '口コミ本文B']);
        [$a, $b] = ReviewItem::orderBy('id')->pluck('id')->all();
        $this->expectDecideMany($run, [
            $this->response([0 => [0.2, 0.6, 0.2], 1 => [0.3, 0.1, 0.6]]),
            // 保留（最大確率 0.5 < 0.7）でも確率は関連度に使う
            $this->response([0 => [0.05, 0.45, 0.5], 1 => [0.0, 0.1, 0.9]]),
        ]);

        $output = app(ReviewJudgmentRunner::class)->run($run, $this->p1(['high', 'mid']));

        $this->assertSame([$a => 0.6, $b => 0.45], $output['relevance']);
        $this->assertSame(2, $output['judged_items']);
        $this->assertSame(2, $output['claims']);
        $this->assertSame(0, $output['dropped_claims']);
        $this->assertSame('jev-1.13.0', $output['model']);
        $this->assertSame(4, ReviewJudgment::where('analysis_run_id', $run->id)->count());
        $this->assertSame('unsure', ReviewJudgment::where('review_item_id', $b)->where('claim_index', 0)->value('relation'));
    }

    public function test_再実行するとこのランの判定を置き換える(): void
    {
        $run = $this->runWithReviews(['口コミ本文A']);
        $this->decider->shouldReceive('decideMany')->twice()->andReturn(
            [$this->response([0 => [0.8, 0.1, 0.1]])],
            [$this->response([0 => [0.1, 0.8, 0.1]])],
        );
        $runner = app(ReviewJudgmentRunner::class);

        $runner->run($run, $this->p1(['high']));
        $runner->run($run, $this->p1(['high']));

        $this->assertSame('support', ReviewJudgment::sole()->relation, 'unique に当たらず、前回の行は残らない');
    }

    public function test_判定が失敗したら何も保存せず例外を再送出する(): void
    {
        $run = $this->runWithReviews(['口コミ本文A']);
        // 1 回目は成功、2 回目は失敗
        $this->decider->shouldReceive('decideMany')->twice()->andReturnUsing(
            fn () => [$this->response([0 => [0.8, 0.1, 0.1]])],
            fn () => throw new \RuntimeException('判定の一括実行で 1 / 1 件が失敗しました。最初の失敗: HTTP 500'),
        );
        $runner = app(ReviewJudgmentRunner::class);
        $runner->run($run, $this->p1(['high']));

        try {
            $runner->run($run, $this->p1(['high']));
            $this->fail('例外が再送出されるはず（握り潰し禁止）');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('1 / 1 件が失敗', $e->getMessage());
        }

        $this->assertSame('contradict', ReviewJudgment::sole()->relation, '失敗した実行は前回の行を消さない');
    }

    public function test_主張が0件なら判定を呼ばない(): void
    {
        $run = $this->runWithReviews(['口コミ本文A']);
        $this->decider->shouldNotReceive('decideMany');

        $this->expectException(\LogicException::class);

        app(ReviewJudgmentRunner::class)->run($run, ['claims' => []]);
    }
}
