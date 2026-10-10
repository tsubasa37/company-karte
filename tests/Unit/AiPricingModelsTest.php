<?php

namespace Tests\Unit;

use App\Enums\AiProvider;
use App\Services\Ai\AiPricing;
use Tests\TestCase;

/**
 * 設定画面のモデル選択の候補（単価表に載っているモデル）。
 * 「壊れた行を黙って候補から消さない（例外で止める）」ことが要点。
 */
class AiPricingModelsTest extends TestCase
{
    private function pricing(): AiPricing
    {
        return new AiPricing;
    }

    public function test_単価表のモデルを単価つきで返す(): void
    {
        config(['ai.pricing.gemini' => [
            'gemini-flash' => ['input' => 0.75, 'cached_input' => 0.075, 'output' => 3.75],
        ]]);

        $this->assertSame([[
            'name' => 'gemini-flash',
            'inputUsd' => 0.75,
            'outputUsd' => 3.75,
            'hasBands' => false,
        ]], $this->pricing()->models(AiProvider::Gemini));
    }

    public function test_段階単価のモデルは通常の帯の単価を返し長文で上がることを伝える(): void
    {
        config(['ai.pricing.gemini' => [
            'gemini-pro' => ['bands' => [
                ['up_to' => 200000, 'input' => 2.00, 'cached_input' => 0.20, 'output' => 12.00],
                ['up_to' => null, 'input' => 4.00, 'cached_input' => 0.40, 'output' => 18.00],
            ]],
        ]]);

        $model = $this->pricing()->models(AiProvider::Gemini)[0];

        $this->assertSame(2.00, $model['inputUsd'], '通常の長さで当たる帯を出す');
        $this->assertSame(12.00, $model['outputUsd']);
        $this->assertTrue($model['hasBands']);
    }

    public function test_codexは単価表を持たないので候補が空(): void
    {
        $this->assertSame([], $this->pricing()->models(AiProvider::Codex));
    }

    public function test_壊れた行があれば候補一覧は黙って減らさず例外になる(): void
    {
        // 候補から黙って外すと「なぜ選べないか」が画面から分からない。単価表は同梱の設定なので、壊れたら直すまで止める
        config(['ai.pricing.openai' => [
            'broken-model' => ['output' => 1.0],
            'good-model' => ['input' => 0.20, 'cached_input' => 0.02, 'output' => 1.20],
        ]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ai.pricing.openai.broken-model');

        $this->pricing()->models(AiProvider::OpenAi);
    }
}
