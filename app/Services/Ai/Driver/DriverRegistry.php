<?php

namespace App\Services\Ai\Driver;

use App\Enums\AiProvider;
use Illuminate\Contracts\Container\Container;

/**
 * AiProvider → Driver の対応表。実体はコンテナから解決する（テストはコンテナ束縛で差し替える）。
 */
class DriverRegistry
{
    public function __construct(private readonly Container $container) {}

    /**
     * @throws \RuntimeException ドライバ未登録のプロバイダ（黙って別の実行先に落とさない）
     */
    public function for(AiProvider $provider): AiDriver
    {
        $class = $this->classFor($provider);

        if ($class === null) {
            throw new \RuntimeException($provider->label().' のドライバは未登録です');
        }

        return $this->container->make($class);
    }

    /** 実行できるプロバイダか。実行先の候補を絞るのに使う（選べるのに必ず落ちる状態を作らない） */
    public function supports(AiProvider $provider): bool
    {
        return $this->classFor($provider) !== null;
    }

    /**
     * プロバイダを足すときはここに1行追加する。
     *
     * @return class-string<AiDriver>|null
     */
    private function classFor(AiProvider $provider): ?string
    {
        return match ($provider) {
            AiProvider::Codex => CodexDriver::class,
            AiProvider::OpenAi => OpenAiDriver::class,
            AiProvider::Gemini => GeminiDriver::class,
            AiProvider::Anthropic => AnthropicDriver::class,
            // 現状は全プロバイダにドライバがある。case を足してここに書き忘れたときだけ null になる
            default => null,
        };
    }
}
