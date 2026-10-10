<?php

namespace Tests\Unit\Decision;

use App\Enums\AiKind;
use App\Enums\AiProvider;
use App\Services\Ai\Decision\DecisionDriverRegistry;
use App\Services\Ai\Decision\JevClient;
use Tests\TestCase;

/**
 * 判定ドライバの対応表。生成用のプロバイダを判定の実行先にしないことが要点。
 */
class DecisionDriverRegistryTest extends TestCase
{
    private function registry(): DecisionDriverRegistry
    {
        return $this->app->make(DecisionDriverRegistry::class);
    }

    public function test_jevはjev_clientで解決される(): void
    {
        $this->assertTrue($this->registry()->supports(AiProvider::Jev));
        $this->assertInstanceOf(JevClient::class, $this->registry()->for(AiProvider::Jev));
    }

    public function test_生成用のプロバイダは判定ドライバ未登録(): void
    {
        foreach (AiProvider::ofKind(AiKind::Generation) as $provider) {
            $this->assertFalse($this->registry()->supports($provider), $provider->value);
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API の判定ドライバは未登録です');

        $this->registry()->for(AiProvider::OpenAi);
    }

    public function test_判定専用のプロバイダには全て判定ドライバがある(): void
    {
        // case を足して対応表に書き忘れると、候補に出ないまま気づけない
        foreach (AiProvider::ofKind(AiKind::Decision) as $provider) {
            $this->assertTrue($this->registry()->supports($provider), $provider->value);
        }
    }
}
