<?php

namespace Tests\Unit;

use App\Enums\AiProvider;
use App\Services\Ai\ApiKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WithApiKeys;
use Tests\TestCase;

/**
 * API キーの読み取り口。置き場所は .env の1本だけで、未設定は
 * 「どの変数に書けばよいか」が分かる日本語の例外で止める（黙って別の実行先に落とさない）。
 */
class ApiKeysTest extends TestCase
{
    use WithApiKeys;

    private function keys(): ApiKeys
    {
        return new ApiKeys;
    }

    public function test_設定されていれば取り出せる(): void
    {
        $this->setApiKey(AiProvider::Gemini, 'AIza-test');

        $this->assertTrue($this->keys()->has(AiProvider::Gemini));
        $this->assertSame('AIza-test', $this->keys()->for(AiProvider::Gemini));
    }

    public function test_前後の空白は取り除く(): void
    {
        // 貼り付けで混ざる改行・空白で認証が落ちるのを防ぐ
        $this->setApiKey(AiProvider::OpenAi, "  sk-test\n");

        $this->assertSame('sk-test', $this->keys()->for(AiProvider::OpenAi));
    }

    /**
     * @return array<int, array{0: string|null}>
     */
    public static function 未設定とみなす値(): array
    {
        return [[null], [''], ['   '], ["\n"]];
    }

    #[DataProvider('未設定とみなす値')]
    public function test_空や空白だけは未設定として扱う(?string $value): void
    {
        $this->setApiKey(AiProvider::Anthropic, $value);

        $this->assertFalse($this->keys()->has(AiProvider::Anthropic));
    }

    public function test_未設定なら変数名を含む日本語の例外で止める(): void
    {
        $this->setApiKey(AiProvider::Anthropic, null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ANTHROPIC_API_KEY が未設定です');

        $this->keys()->for(AiProvider::Anthropic);
    }

    public function test_codexはキーを使わないので呼び出し側の誤りとして落とす(): void
    {
        $this->assertFalse($this->keys()->has(AiProvider::Codex));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('API キーを使いません');

        $this->keys()->for(AiProvider::Codex);
    }
}
