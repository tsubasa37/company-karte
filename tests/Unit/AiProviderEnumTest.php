<?php

namespace Tests\Unit;

use App\Enums\AiKind;
use App\Enums\AiProvider;
use App\Services\Ai\ProviderOptionFields;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 実行先 enum の網羅。default の無い match はプロバイダを足すと UnhandledMatchError になり、
 * 設定画面が全プロバイダを回すため /settings ごと 500 になる。PHPStan が無いのでここで捕まえる。
 */
class AiProviderEnumTest extends TestCase
{
    /**
     * @return array<string, array{AiProvider}>
     */
    public static function providers(): array
    {
        return collect(AiProvider::cases())->mapWithKeys(fn (AiProvider $p) => [$p->value => [$p]])->all();
    }

    #[DataProvider('providers')]
    public function test_全ての実行先で表示用の値と種類が返る(AiProvider $provider): void
    {
        $this->assertNotSame('', $provider->label());
        $this->assertNotSame('', $provider->modelPlaceholder());
        $this->assertStringStartsWith('https://', $provider->docsUrl());
        $this->assertInstanceOf(AiKind::class, $provider->kind());
        // サブプロセス実行だけがキーを持たない
        $this->assertSame($provider->isSubprocess(), $provider->apiKeyEnvName() === null);
    }

    #[DataProvider('providers')]
    public function test_全ての実行先で固有オプションと注記が返る(AiProvider $provider): void
    {
        $fields = $this->app->make(ProviderOptionFields::class);

        $this->assertIsArray($fields->fieldsFor($provider));
        $this->assertNotEmpty($fields->notesFor($provider), 'データの送り先の注記は全実行先で要る');
    }

    public function test_生成用は4件で判定専用のjevを含まない(): void
    {
        $generation = AiProvider::ofKind(AiKind::Generation);

        $this->assertSame([AiProvider::Codex, AiProvider::OpenAi, AiProvider::Gemini, AiProvider::Anthropic], $generation);
        $this->assertNotContains(AiProvider::Jev, $generation);
    }

    public function test_判定専用はjevだけ(): void
    {
        $this->assertSame([AiProvider::Jev], AiProvider::ofKind(AiKind::Decision));
        $this->assertSame('TYPESAFE_API_KEY', AiProvider::Jev->apiKeyEnvName());
        $this->assertFalse(AiProvider::Jev->isSubprocess());
        $this->assertSame([], $this->app->make(ProviderOptionFields::class)->fieldsFor(AiProvider::Jev));
    }
}
