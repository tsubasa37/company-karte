<?php

namespace Tests\Unit\Decision;

use App\Enums\AiProvider;
use App\Services\Ai\Decision\ChoiceAnswer;
use App\Services\Ai\Decision\ChoiceQuestion;
use App\Services\Ai\Decision\DecisionRequest;
use App\Services\Ai\Decision\DecisionResponse;
use App\Services\Ai\Decision\NoulAnswer;
use App\Services\Ai\Decision\NoulQuestion;
use App\Services\Ai\Decision\ScoreQuestion;
use Tests\TestCase;

/**
 * 判定の質問と入力。「送る形が API 契約どおり」「契約違反は組み立てた時点で落ちる」ことが要点。
 */
class DecisionQuestionTest extends TestCase
{
    private const CRITERIA = ['consistent' => '整合している', 'contradicts' => '矛盾している'];

    public function test_choiceの送る形(): void
    {
        $question = new ChoiceQuestion('q1', '求人票と口コミは整合しているか', self::CRITERIA);

        $this->assertSame('q1', $question->id());
        $this->assertSame(['consistent', 'contradicts'], $question->options());
        $this->assertSame(
            ['type' => 'choice', 'instructions' => '求人票と口コミは整合しているか', 'criteria' => self::CRITERIA],
            $question->toPayload(),
        );
    }

    public function test_scoreの送る形(): void
    {
        $question = new ScoreQuestion('q2', '残業の多さ', ['少ない', '普通', '多い']);

        $this->assertSame(
            ['type' => 'score', 'instructions' => '残業の多さ', 'levels' => ['少ない', '普通', '多い']],
            $question->toPayload(),
        );
    }

    public function test_noulの送る形(): void
    {
        $question = new NoulQuestion('q3', '具体的な体験に基づくか');

        $this->assertSame(['type' => 'noul', 'instructions' => '具体的な体験に基づくか'], $question->toPayload());
    }

    public function test_choiceの候補が1個なら例外(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('2 個以上必要です');

        new ChoiceQuestion('q1', '判定', ['only' => '1つだけ']);
    }

    public function test_choiceの候補名が空なら例外(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('空の候補名');

        new ChoiceQuestion('q1', '判定', ['a' => 'A', ' ' => '空白']);
    }

    public function test_choiceの候補が連番の配列なら例外(): void
    {
        // 0 からの連番の鍵は JSON で配列になり、候補名の対応が失われる
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('連想配列');

        new ChoiceQuestion('q1', '判定', ['A', 'B']);
    }

    public function test_idが空なら例外(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('質問 id が空です');

        new ChoiceQuestion('', '判定', self::CRITERIA);
    }

    public function test_数字だけのidは例外(): void
    {
        // 0 からの連番の id だと questions が JSON の配列に化ける
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('数字だけ');

        new NoulQuestion('0', '判定');
    }

    public function test_scoreの段階が1個なら例外(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('2〜10 個にしてください（1 個）');

        new ScoreQuestion('q2', '採点', ['1つだけ']);
    }

    public function test_scoreの段階が11個なら例外(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('2〜10 個にしてください（11 個）');

        new ScoreQuestion('q2', '採点', array_map(fn (int $i) => "段階{$i}", range(1, 11)));
    }

    public function test_scoreの段階名が重複していれば例外(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('重複');

        new ScoreQuestion('q2', '採点', ['低', '低']);
    }

    public function test_質問が0件なら例外(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('質問が 0 件です');

        new DecisionRequest('材料', []);
    }

    public function test_質問idが重複していれば例外(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('重複しています（q1）');

        new DecisionRequest('材料', [new NoulQuestion('q1', 'A'), new NoulQuestion('q1', 'B')]);
    }

    public function test_stateが空なら例外(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('state が空です');

        new DecisionRequest('  ', [new NoulQuestion('q1', 'A')]);
    }

    public function test_文字数は配列のstateをjsonの長さで数え質問側は本文の合計(): void
    {
        $request = new DecisionRequest(['本文' => 'あいう'], [
            new ChoiceQuestion('c', '選ぶ', ['はい' => '肯定', 'いいえ' => '否定']),
            new ScoreQuestion('s', '採点', ['低', '高']),
        ]);

        // {"本文":"あいう"} = 12 字
        $this->assertSame(12, $request->stateChars());
        // c(1) + 選ぶ(2) + はい肯定(4) + いいえ否定(5) + s(1) + 採点(2) + 低高(2)
        $this->assertSame(17, $request->questionsChars());
        $this->assertSame(['c', 's'], array_keys($request->questionsPayload()));
        $this->assertSame('s', $request->question('s')->id());
    }

    public function test_応答から型違いの回答を読むとlogic例外(): void
    {
        $response = new DecisionResponse(
            ['q1' => new NoulAnswer(0.9), 'q2' => new ChoiceAnswer('a', ['a' => 1.0, 'b' => 0.0], 0.8)],
            null,
            AiProvider::Jev,
            'jev-1.13.0',
        );

        $this->assertTrue($response->has('q1'));
        $this->assertFalse($response->has('q9'));
        $this->assertSame(0.9, $response->noul('q1')->noul);
        $this->assertSame('a', $response->choice('q2')->choice);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('ChoiceAnswer として読めません');

        $response->choice('q1');
    }
}
