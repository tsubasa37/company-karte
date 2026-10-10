<?php

namespace Tests\Unit;

use App\Enums\AiProvider;
use App\Services\Ai\AiPricing;
use App\Services\Ai\Driver\TokenUsage;
use Tests\TestCase;

/**
 * 推定費用の計算。「単価が無い・トークンが無いときに 0 を作らない」「段階単価を入力総量で選ぶ」ことが要点。
 */
class AiPricingTest extends TestCase
{
    /** 一般的な形の単価（cache_write あり） */
    private const FLAT = ['input' => 4.00, 'cached_input' => 0.40, 'cache_write' => 5.00, 'output' => 20.00];

    private function pricing(): AiPricing
    {
        return new AiPricing;
    }

    public function test_入力の内訳と出力に単価を掛けてマイクロusdの整数になる(): void
    {
        config()->set('ai.pricing.openai', ['gpt-test' => self::FLAT]);
        $tokens = new TokenUsage(input: 1000, cached: 200, cacheWrite: 100, output: 50, reasoning: 10);

        // (1000 − 200 − 100) × 4.00 + 200 × 0.40 + 100 × 5.00 + 50 × 20.00 = 2800 + 80 + 500 + 1000
        $this->assertSame(4380, $this->pricing()->costMicroUsd(AiProvider::OpenAi, 'gpt-test', $tokens));
    }

    public function test_reasoningは出力の内数なので費用に影響しない(): void
    {
        config()->set('ai.pricing.openai', ['gpt-test' => self::FLAT]);

        $with = $this->pricing()->costMicroUsd(AiProvider::OpenAi, 'gpt-test', new TokenUsage(input: 100, output: 50, reasoning: 40));
        $without = $this->pricing()->costMicroUsd(AiProvider::OpenAi, 'gpt-test', new TokenUsage(input: 100, output: 50));

        $this->assertSame(1400, $with);
        $this->assertSame($without, $with);
    }

    public function test_ドットを含むモデル名でも単価を引ける(): void
    {
        // config のドット記法だと "gpt-5.6-test" が階層に分解されて引けなくなる
        config()->set('ai.pricing.openai', ['gpt-5.6-test' => self::FLAT]);

        $this->assertSame(400, $this->pricing()->costMicroUsd(AiProvider::OpenAi, 'gpt-5.6-test', new TokenUsage(input: 100, output: 0)));
    }

    public function test_段階単価は入力総量で帯を選ぶ(): void
    {
        config()->set('ai.pricing.gemini', ['gemini-test' => ['bands' => [
            ['up_to' => 200000, 'input' => 2.00, 'cached_input' => 0.20, 'output' => 12.00],
            ['up_to' => null, 'input' => 4.00, 'cached_input' => 0.40, 'output' => 18.00],
        ]]]);

        // 境界の 200,000 は下の帯（<= up_to）。cached を含む総入力で判定する
        $this->assertSame(400_120, $this->pricing()->costMicroUsd(AiProvider::Gemini, 'gemini-test', new TokenUsage(input: 200000, output: 10)));
        $this->assertSame(800_184, $this->pricing()->costMicroUsd(AiProvider::Gemini, 'gemini-test', new TokenUsage(input: 200001, output: 10)));
        $this->assertSame(
            (int) round(150_000 * 4.00 + 50_001 * 0.40 + 10 * 18.00),
            $this->pricing()->costMicroUsd(AiProvider::Gemini, 'gemini-test', new TokenUsage(input: 200001, cached: 50001, output: 10)),
        );
    }

    public function test_cache_write単価が無いモデルは書き込み分も入力単価で計算する(): void
    {
        config()->set('ai.pricing.gemini', ['gemini-test' => ['input' => 0.75, 'cached_input' => 0.075, 'output' => 3.75]]);
        $tokens = new TokenUsage(input: 1000, cached: 100, cacheWrite: 300, output: 0);

        // (1000 − 100) × 0.75 + 100 × 0.075 = 675 + 7.5 → 四捨五入で 683
        $this->assertSame(683, $this->pricing()->costMicroUsd(AiProvider::Gemini, 'gemini-test', $tokens));
    }

    public function test_単価未登録のモデルはnull(): void
    {
        config()->set('ai.pricing.openai', ['gpt-test' => self::FLAT]);

        $this->assertNull($this->pricing()->costMicroUsd(AiProvider::OpenAi, 'gpt-unknown', new TokenUsage(input: 100, output: 10)));
        $this->assertNull($this->pricing()->costMicroUsd(AiProvider::Anthropic, 'gpt-test', new TokenUsage(input: 100, output: 10)), '他社の表は引かない');
    }

    public function test_モデルやトークンが不明ならnullで0にしない(): void
    {
        config()->set('ai.pricing.openai', ['gpt-test' => self::FLAT]);

        $this->assertNull($this->pricing()->costMicroUsd(AiProvider::OpenAi, null, new TokenUsage(input: 100, output: 10)));
        $this->assertNull($this->pricing()->costMicroUsd(AiProvider::OpenAi, 'gpt-test', null));
        $this->assertNull($this->pricing()->costMicroUsd(AiProvider::OpenAi, 'gpt-test', new TokenUsage(input: null, output: 10)));
        $this->assertNull($this->pricing()->costMicroUsd(AiProvider::OpenAi, 'gpt-test', new TokenUsage(input: 100, output: null)));
    }

    public function test_codexはサブスクなので単価表があってもnull(): void
    {
        config()->set('ai.pricing.codex', ['gpt-test' => self::FLAT]);

        $this->assertNull($this->pricing()->costMicroUsd(AiProvider::Codex, 'gpt-test', new TokenUsage(input: 100, output: 10)));
    }

    public function test_マイクロusd未満は四捨五入する(): void
    {
        config()->set('ai.pricing.openai', ['gpt-test' => ['input' => 0.40, 'cached_input' => 0.04, 'output' => 0.50]]);

        $this->assertSame(0, $this->pricing()->costMicroUsd(AiProvider::OpenAi, 'gpt-test', new TokenUsage(input: 1, output: 0)), '0.4 → 0');
        $this->assertSame(2, $this->pricing()->costMicroUsd(AiProvider::OpenAi, 'gpt-test', new TokenUsage(input: 0, output: 3)), '1.5 → 2');
    }

    public function test_必須の単価が欠けた表は例外にする(): void
    {
        config()->set('ai.pricing.openai', ['gpt-test' => ['input' => 4.00, 'cached_input' => 0.40]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('output の単価がありません');

        $this->pricing()->costMicroUsd(AiProvider::OpenAi, 'gpt-test', new TokenUsage(input: 100, output: 10));
    }

    public function test_無制限の帯が無い段階単価は例外にする(): void
    {
        config()->set('ai.pricing.gemini', ['gemini-test' => ['bands' => [
            ['up_to' => 200000, 'input' => 2.00, 'cached_input' => 0.20, 'output' => 12.00],
        ]]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('up_to => null');

        $this->pricing()->costMicroUsd(AiProvider::Gemini, 'gemini-test', new TokenUsage(input: 300000, output: 10));
    }

    public function test_キャッシュ分が総入力を超える内訳は例外にする(): void
    {
        config()->set('ai.pricing.openai', ['gpt-test' => self::FLAT]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('総入力を超えています');

        $this->pricing()->costMicroUsd(AiProvider::OpenAi, 'gpt-test', new TokenUsage(input: 100, cached: 200, output: 10));
    }
}
