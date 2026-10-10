<?php

namespace Tests\Unit;

use App\Enums\AiProvider;
use App\Enums\AiPurpose;
use App\Enums\AiTier;
use App\Models\AiSetting;
use App\Models\OpenIssue;
use App\Services\Ai\AiDecider;
use App\Services\Ai\AiRunner;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Decision\ChoiceAnswer;
use App\Services\Ai\Decision\ChoiceQuestion;
use App\Services\Ai\Decision\DecisionRequest;
use App\Services\Ai\Decision\DecisionResponse;
use App\Services\Ai\Decision\NoulAnswer;
use App\Services\Ai\IssueResolutionMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * 面接メモと論点の照合。同意スイッチで経路が分かれることと、
 * 機械判定の経路で送る state・質問の形、しきい値による確定、引用の選び方、文の分割、例外の伝播を見る。
 */
class IssueResolutionMatcherTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = 7;

    private MockInterface $runner;

    private MockInterface $decider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runner = Mockery::mock(AiRunner::class);
        $this->decider = Mockery::mock(AiDecider::class);
    }

    /** 同意スイッチの結果を固定した照合クラス */
    private function matcher(bool $allowed): IssueResolutionMatcher
    {
        $settings = Mockery::mock(AiSettings::class);
        $settings->shouldReceive('isDecisionInterviewNotesAllowed')->andReturn($allowed);

        return new IssueResolutionMatcher($this->runner, $this->decider, $settings);
    }

    /** DB の設定をそのまま読む照合クラス（同意スイッチと判定エンジンの組み合わせを見る） */
    private function matcherWithStoredSettings(): IssueResolutionMatcher
    {
        return new IssueResolutionMatcher($this->runner, $this->decider, $this->app->make(AiSettings::class));
    }

    /**
     * @return Collection<int, OpenIssue>
     */
    private function issues(int $count): Collection
    {
        return collect(range(0, $count - 1))->map(fn (int $i) => new OpenIssue([
            'claim' => "主張{$i}",
            'verdict' => 'unverifiable',
            'rationale' => "理由{$i}",
        ]));
    }

    /**
     * @param  array<int, array{0: string, 1: float, 2: float}>  $byIssue  issue_index => [最大確率の候補, その確率, confidence]
     */
    private function statusResponse(array $byIssue): DecisionResponse
    {
        $answers = [];
        foreach ($byIssue as $i => [$best, $p, $confidence]) {
            $rest = (1 - $p) / 3;
            $probabilities = array_fill_keys(['resolved', 'unresolved', 'worsened', 'not_mentioned'], $rest);
            $probabilities[$best] = $p;
            $answers["status_{$i}"] = new ChoiceAnswer($best, $probabilities, $confidence);
        }

        return new DecisionResponse($answers, null, AiProvider::Jev, 'jev-1.13.0');
    }

    /**
     * @param  list<float>  $nouls  文 j => noul
     */
    private function evidenceResponse(array $nouls): DecisionResponse
    {
        $answers = [];
        foreach ($nouls as $j => $noul) {
            $answers["touches_{$j}"] = new NoulAnswer($noul);
        }

        return new DecisionResponse($answers, null, AiProvider::Jev, 'jev-1.13.0');
    }

    /**
     * 状態の判定を 1 回だけ受け、渡されたリクエストを $captured に残して応答を返す。
     */
    private function expectDecide(DecisionResponse $response, ?DecisionRequest &$captured = null): void
    {
        $this->decider->shouldReceive('decide')
            ->once()
            ->with(Mockery::type(DecisionRequest::class), AiPurpose::IssueMatch, 'jev', self::COMPANY_ID)
            ->andReturnUsing(function (DecisionRequest $request) use ($response, &$captured) {
                $captured = $request;

                return $response;
            });
    }

    /**
     * 引用の判定を 1 回だけ受け、渡されたリクエストを $captured に残して応答を返す。
     *
     * @param  list<DecisionResponse>  $responses
     * @param  list<DecisionRequest>|null  $captured
     */
    private function expectDecideMany(array $responses, ?array &$captured = null): void
    {
        $this->decider->shouldReceive('decideMany')
            ->once()
            ->with(Mockery::type('array'), AiPurpose::IssueMatch, 'jev', self::COMPANY_ID)
            ->andReturnUsing(function (array $requests) use ($responses, &$captured) {
                $captured = $requests;

                return $responses;
            });
    }

    private function expectLlmPath(): void
    {
        $this->runner->shouldReceive('runWithSchema')
            ->once()
            ->with(Mockery::type('array'), Mockery::type('string'), AiTier::Light, AiPurpose::IssueMatch, null, self::COMPANY_ID)
            ->andReturn(['matches' => [
                ['issue_index' => 0, 'outcome' => 'resolved', 'quote' => '原文', 'note' => '理由'],
            ]]);
        $this->decider->shouldNotReceive('decide');
        $this->decider->shouldNotReceive('decideMany');
    }

    public function test_同意オフなら判定エンジンが設定済みでも従来の経路で判定する(): void
    {
        AiSetting::sole()->update(['decision_provider' => AiProvider::Jev, 'decision_send_interview_notes' => false]);
        $this->expectLlmPath();

        $result = $this->matcherWithStoredSettings()->match($this->issues(1), '面接メモ。', self::COMPANY_ID);

        $this->assertSame([['issue_index' => 0, 'outcome' => 'resolved', 'quote' => '原文', 'note' => '理由']], $result);
    }

    public function test_同意オンでも判定エンジンが未設定なら従来の経路で判定する(): void
    {
        AiSetting::sole()->update(['decision_provider' => null, 'decision_send_interview_notes' => true]);
        $this->expectLlmPath();

        $this->matcherWithStoredSettings()->match($this->issues(1), '面接メモ。', self::COMPANY_ID);
    }

    public function test_状態の判定は論点と面接メモだけを送り論点ごとに質問する(): void
    {
        $this->runner->shouldNotReceive('runWithSchema');
        $this->decider->shouldNotReceive('decideMany');
        $this->expectDecide($this->statusResponse([
            0 => ['not_mentioned', 0.9, 0.9],
            1 => ['not_mentioned', 0.9, 0.9],
        ]), $captured);

        $this->matcher(true)->match($this->issues(2), "残業は少ない。\n評価は半期ごと。", self::COMPANY_ID);

        $state = $captured->state;
        $this->assertSame(['issues', 'notes', 'rule'], array_keys($state), '職務経歴・選考メモ等は送らない');
        $this->assertSame(
            [
                ['index' => 0, 'claim' => '主張0', 'verdict' => 'unverifiable', 'rationale' => '理由0'],
                ['index' => 1, 'claim' => '主張1', 'verdict' => 'unverifiable', 'rationale' => '理由1'],
            ],
            $state['issues'],
        );
        $this->assertSame("残業は少ない。\n評価は半期ごと。", $state['notes']);

        $this->assertSame(['status_0', 'status_1'], array_map(fn ($q) => $q->id(), $captured->questions));
        $question = $captured->question('status_1');
        $this->assertInstanceOf(ChoiceQuestion::class, $question);
        $this->assertSame(['resolved', 'unresolved', 'worsened', 'not_mentioned'], $question->options());
        $this->assertStringContainsString('issues[1]', $question->instructions);
    }

    public function test_面接メモは先頭8000字だけを送る(): void
    {
        $this->expectDecide($this->statusResponse([0 => ['not_mentioned', 0.9, 0.9]]), $captured);

        $this->matcher(true)->match($this->issues(1), str_repeat('あ', 8100), self::COMPANY_ID);

        $this->assertSame(8000, mb_strlen($captured->state['notes']));
    }

    public function test_しきい値ちょうどは確定し未達は状態を変えない(): void
    {
        $this->expectDecide($this->statusResponse([
            0 => ['resolved', 0.7, 0.5],
            1 => ['unresolved', 0.69, 0.9],
            2 => ['worsened', 0.9, 0.49],
            3 => ['not_mentioned', 0.8, 0.9],
        ]));
        $this->expectDecideMany([$this->evidenceResponse([0.9])], $captured);

        $result = $this->matcher(true)->match($this->issues(4), '残業は月20時間程度と聞いた。', self::COMPANY_ID);

        $this->assertCount(1, $captured, '引用は状態が確定した論点だけに聞く');
        $this->assertSame([
            ['issue_index' => 0, 'outcome' => 'resolved', 'quote' => '残業は月20時間程度と聞いた。', 'note' => '機械判定（解消）。面接記録の文 0 を根拠にした'],
            ['issue_index' => 1, 'outcome' => 'not_mentioned', 'quote' => '', 'note' => ''],
            ['issue_index' => 2, 'outcome' => 'not_mentioned', 'quote' => '', 'note' => ''],
            ['issue_index' => 3, 'outcome' => 'not_mentioned', 'quote' => '', 'note' => ''],
        ], $result, '戻り値は従来の経路と同じ形');
    }

    public function test_引用は触れている度合いが最大の文にする(): void
    {
        $this->expectDecide($this->statusResponse([
            0 => ['not_mentioned', 0.9, 0.9],
            1 => ['worsened', 0.8, 0.8],
        ]));
        $this->expectDecideMany([$this->evidenceResponse([0.2, 0.5, 0.3])], $captured);

        $result = $this->matcher(true)->match($this->issues(2), '給与は年俸制です。残業は月45時間と聞いた！評価は半期ごと？', self::COMPANY_ID);

        $request = $captured[0];
        $this->assertSame(['issue', 'sentences', 'rule'], array_keys($request->state));
        $this->assertSame(['claim' => '主張1', 'verdict' => 'unverifiable', 'rationale' => '理由1'], $request->state['issue']);
        $this->assertSame([
            ['j' => 0, 'text' => '給与は年俸制です。'],
            ['j' => 1, 'text' => '残業は月45時間と聞いた！'],
            ['j' => 2, 'text' => '評価は半期ごと？'],
        ], $request->state['sentences']);
        $this->assertSame(['touches_0', 'touches_1', 'touches_2'], array_map(fn ($q) => $q->id(), $request->questions));

        $this->assertSame('worsened', $result[1]['outcome']);
        $this->assertSame('残業は月45時間と聞いた！', $result[1]['quote'], '下限ちょうどの文は採る');
        $this->assertSame('機械判定（懸念が強まった）。面接記録の文 1 を根拠にした', $result[1]['note']);
    }

    public function test_触れている度合いが下限未満なら引用を空にする(): void
    {
        $this->expectDecide($this->statusResponse([0 => ['unresolved', 0.8, 0.8]]));
        $this->expectDecideMany([$this->evidenceResponse([0.49, 0.1])]);

        $result = $this->matcher(true)->match($this->issues(1), '一文目。二文目。', self::COMPANY_ID);

        $this->assertSame([['issue_index' => 0, 'outcome' => 'unresolved', 'quote' => '', 'note' => '機械判定（未解消）']], $result);
    }

    public function test_文は句点と感嘆符と疑問符と改行で区切る(): void
    {
        $long = str_repeat('長', 250);
        $this->expectDecide($this->statusResponse([0 => ['resolved', 0.9, 0.9]]));
        $this->expectDecideMany([$this->evidenceResponse(array_fill(0, 9, 0.1))], $captured);

        $this->matcher(true)->match(
            $this->issues(1),
            "一文目。二文目！三文目？four!five?\n\n  六文目  \r\n本当？！続き\n　\n{$long}",
            self::COMPANY_ID,
        );

        $this->assertSame(
            ['一文目。', '二文目！', '三文目？', 'four!', 'five?', '六文目', '本当？！', '続き', $long],
            array_column($captured[0]->state['sentences'], 'text'),
            '空の行は除き、200 字を超える文も 1 文のまま',
        );
    }

    public function test_論点の根拠が長く上限を超えるときは面接メモの末尾を削って収める(): void
    {
        config(['ai.decision.limits.max_state_chars' => 3000]);
        $issues = collect([new OpenIssue(['claim' => '主張0', 'verdict' => 'unverifiable', 'rationale' => str_repeat('根', 1500)])]);
        $this->expectDecide($this->statusResponse([0 => ['not_mentioned', 0.9, 0.9]]), $captured);

        $this->matcher(true)->match($issues, str_repeat('あ', 5000), self::COMPANY_ID);

        $this->assertLessThanOrEqual(3000, $captured->stateChars());
        $this->assertStringStartsWith('あああ', $captured->state['notes'], '先頭は残す');
    }

    public function test_論点の一覧だけで上限を超えるなら判定せず例外にする(): void
    {
        config(['ai.decision.limits.max_state_chars' => 100]);
        $this->decider->shouldNotReceive('decide');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('論点の一覧だけで判定の上限');

        $this->matcher(true)->match($this->issues(3), '面接で確認した。', self::COMPANY_ID);
    }

    public function test_文が無ければ通信せず全件を言及なしで返す(): void
    {
        $this->runner->shouldNotReceive('runWithSchema');
        $this->decider->shouldNotReceive('decide');
        $this->decider->shouldNotReceive('decideMany');

        $result = $this->matcher(true)->match($this->issues(2), "  \n\n　\r\n", self::COMPANY_ID);

        $this->assertSame([
            ['issue_index' => 0, 'outcome' => 'not_mentioned', 'quote' => '', 'note' => ''],
            ['issue_index' => 1, 'outcome' => 'not_mentioned', 'quote' => '', 'note' => ''],
        ], $result);
    }

    public function test_文が質問数の上限を超えたら先頭の分だけを候補にし件数を書く(): void
    {
        config()->set('ai.decision.limits.max_questions', 2);
        $this->expectDecide($this->statusResponse([0 => ['resolved', 0.9, 0.9]]));
        $this->expectDecideMany([$this->evidenceResponse([0.1, 0.9])], $captured);

        $result = $this->matcher(true)->match($this->issues(1), '一文目。二文目。三文目。四文目。', self::COMPANY_ID);

        $this->assertSame(['一文目。', '二文目。'], array_column($captured[0]->state['sentences'], 'text'));
        $this->assertSame('二文目。', $result[0]['quote']);
        $this->assertSame('機械判定（解消）。面接記録の文 1 を根拠にした。文が多いため先頭 2 文だけを根拠の候補にした（残り 2 文は候補にしていない）', $result[0]['note']);
    }

    public function test_状態の判定が失敗したら例外をそのまま投げる(): void
    {
        $this->runner->shouldNotReceive('runWithSchema');
        $this->decider->shouldReceive('decide')->once()->andThrow(new \RuntimeException('判定エンジンの失敗'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('判定エンジンの失敗');

        $this->matcher(true)->match($this->issues(1), '面接メモ。', self::COMPANY_ID);
    }

    public function test_引用の判定が失敗したら例外をそのまま投げる(): void
    {
        $this->runner->shouldNotReceive('runWithSchema');
        $this->expectDecide($this->statusResponse([0 => ['resolved', 0.9, 0.9]]));
        $this->decider->shouldReceive('decideMany')->once()->andThrow(new \RuntimeException('引用の判定の失敗'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('引用の判定の失敗');

        $this->matcher(true)->match($this->issues(1), '面接メモ。', self::COMPANY_ID);
    }
}
