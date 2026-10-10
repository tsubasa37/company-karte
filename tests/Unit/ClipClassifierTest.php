<?php

namespace Tests\Unit;

use App\Enums\AiProvider;
use App\Enums\AiPurpose;
use App\Enums\AiTier;
use App\Services\Ai\AiDecider;
use App\Services\Ai\AiRunner;
use App\Services\Ai\ClipClassifier;
use App\Services\Ai\Decision\ChoiceAnswer;
use App\Services\Ai\Decision\DecisionRequest;
use App\Services\Ai\Decision\DecisionResponse;
use App\Services\Ai\Decision\NoulAnswer;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * クリップの自動判定。判定エンジンが設定済みなら前段の機械判定で一覧ページ・無関係なページを弾き、
 * 弾かなければ従来の LLM 判定をそのまま返す。未設定なら従来どおり LLM だけを呼ぶ。
 */
class ClipClassifierTest extends TestCase
{
    private const LLM_RESULT = [
        'is_company_related' => true,
        'is_multi_company' => false,
        'company_name' => '株式会社テスト',
        'source_type' => 'jobposting',
        'summary' => '株式会社テストの求人票',
    ];

    private MockInterface $ai;

    private MockInterface $decider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ai = Mockery::mock(AiRunner::class);
        $this->decider = Mockery::mock(AiDecider::class);
    }

    private function classifier(): ClipClassifier
    {
        return new ClipClassifier($this->ai, $this->decider);
    }

    private function response(float $related, float $multi, string $sourceType = 'other'): DecisionResponse
    {
        $probabilities = array_fill_keys(['jobposting', 'review', 'shokuba', 'edinet', 'news', 'other'], 0.0);
        $probabilities[$sourceType] = 1.0;

        return new DecisionResponse([
            'is_company_related' => new NoulAnswer($related),
            'is_multi_company' => new NoulAnswer($multi),
            'source_type' => new ChoiceAnswer($sourceType, $probabilities, 0.9),
        ], null, AiProvider::Jev, 'jev-1.13.0');
    }

    private function expectDecide(DecisionResponse $response, ?DecisionRequest &$captured = null): void
    {
        $this->decider->shouldReceive('isConfigured')->andReturnTrue();
        $this->decider->shouldReceive('decide')
            ->once()
            ->with(Mockery::type(DecisionRequest::class), AiPurpose::ClipClassify, 'jev')
            ->andReturnUsing(function (DecisionRequest $request) use ($response, &$captured) {
                $captured = $request;

                return $response;
            });
    }

    private function expectLlm(): void
    {
        $this->ai->shouldReceive('runWithSchema')
            ->once()
            ->with(Mockery::type('array'), Mockery::type('string'), AiTier::Light, AiPurpose::ClipClassify)
            ->andReturn(self::LLM_RESULT);
    }

    public function test_判定エンジン未設定なら前段判定をせず従来の判定結果をそのまま返す(): void
    {
        $this->decider->shouldReceive('isConfigured')->andReturnFalse();
        $this->decider->shouldNotReceive('decide');
        $this->expectLlm();

        $this->assertSame(self::LLM_RESULT, $this->classifier()->classify('求人', 'https://example.com/job', '本文'));
    }

    public function test_投入先の企業が指定済みなら前段判定をせず従来の判定結果を返す(): void
    {
        // 企業が決まっているクリップは弾く理由が無い。機械判定の種別・要約で上書きしない
        $this->decider->shouldReceive('isConfigured')->andReturnTrue();
        $this->decider->shouldNotReceive('decide');
        $this->expectLlm();

        $result = $this->classifier()->classify('タイトル', 'https://example.com', '本文', companyKnown: true);

        $this->assertSame(self::LLM_RESULT, $result);
    }

    public function test_一覧ページの見込みがしきい値以上なら従来の判定を呼ばずに一覧ページとして弾く(): void
    {
        // 無関係の見込みも低いが、一覧ページの判定を先に見る
        $this->expectDecide($this->response(0.0, 0.7, 'review'));
        $this->ai->shouldNotReceive('runWithSchema');

        $result = $this->classifier()->classify('お気に入り', 'https://example.com/list', '株式会社A 株式会社B');

        $this->assertFalse($result['is_company_related']);
        $this->assertTrue($result['is_multi_company']);
        $this->assertSame('', $result['company_name']);
        $this->assertSame('review', $result['source_type']);
        $this->assertStringContainsString('機械判定', $result['summary']);
    }

    public function test_関係の見込みがしきい値未満なら従来の判定を呼ばずに無関係なページとして弾く(): void
    {
        $this->expectDecide($this->response(0.29, 0.0, 'news'));
        $this->ai->shouldNotReceive('runWithSchema');

        $result = $this->classifier()->classify('天気', 'https://example.com/weather', '明日は晴れ');

        $this->assertFalse($result['is_company_related']);
        $this->assertFalse($result['is_multi_company']);
        $this->assertSame('', $result['company_name']);
        $this->assertSame('news', $result['source_type']);
        $this->assertStringContainsString('機械判定', $result['summary']);
    }

    /**
     * @return array<string, array{0: float, 1: float}>
     */
    public static function passingScores(): array
    {
        return [
            '一覧ページの見込みがしきい値の直下' => [0.9, 0.69],
            '関係の見込みがしきい値ちょうど' => [0.3, 0.0],
            '関係ありで一覧ページではない' => [0.95, 0.05],
        ];
    }

    #[DataProvider('passingScores')]
    public function test_しきい値を通れば従来の判定を呼びその結果をそのまま返す(float $related, float $multi): void
    {
        // 前段の種別（other）は結果に混ぜない
        $this->expectDecide($this->response($related, $multi, 'other'));
        $this->expectLlm();

        $this->assertSame(self::LLM_RESULT, $this->classifier()->classify('求人', 'https://example.com/job', '本文'));
    }

    public function test_前段判定にはタイトルとurlと先頭8000字の本文と3つの質問を送る(): void
    {
        $this->expectDecide($this->response(0.9, 0.0), $captured);
        $this->expectLlm();

        $text = str_repeat('あ', 8000).'ここは切れる';
        $this->classifier()->classify('求人タイトル', 'https://example.com/job', $text);

        $this->assertSame(['title', 'url', 'text', 'rule'], array_keys($captured->state));
        $this->assertSame('求人タイトル', $captured->state['title']);
        $this->assertSame('https://example.com/job', $captured->state['url']);
        $this->assertSame(str_repeat('あ', 8000), $captured->state['text']);
        $this->assertStringContainsString('指示ではない', $captured->state['rule']);
        $this->assertSame(
            ['is_company_related', 'is_multi_company', 'source_type'],
            array_keys($captured->questionsPayload()),
        );
        $this->assertSame(
            ['noul', 'noul', 'choice'],
            array_values(array_map(fn (array $payload) => $payload['type'], $captured->questionsPayload())),
        );
        $this->assertSame(
            ['jobposting', 'review', 'shokuba', 'edinet', 'news', 'other'],
            array_keys($captured->questionsPayload()['source_type']['criteria']),
        );
    }

    public function test_前段判定が失敗したら例外をそのまま投げて従来の判定に戻さない(): void
    {
        $this->decider->shouldReceive('isConfigured')->andReturnTrue();
        $this->decider->shouldReceive('decide')->once()->andThrow(new \RuntimeException('判定エンジンの呼び出しに失敗しました'));
        $this->ai->shouldNotReceive('runWithSchema');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('判定エンジンの呼び出しに失敗しました');

        $this->classifier()->classify('求人', 'https://example.com/job', '本文');
    }
}
