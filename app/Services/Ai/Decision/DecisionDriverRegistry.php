<?php

namespace App\Services\Ai\Decision;

use App\Enums\AiProvider;
use Illuminate\Contracts\Container\Container;

/**
 * AiProvider → 判定ドライバの対応表。実体はコンテナから解決する（テストはコンテナ束縛で差し替える）。
 *
 * 生成の DriverRegistry には載せない。判定エンジンは schema + prompt → 構造化出力の契約（AiDriver）を満たせないため。
 */
class DecisionDriverRegistry
{
    public function __construct(private readonly Container $container) {}

    /**
     * @throws \RuntimeException 判定ドライバ未登録のプロバイダ（黙って別の実行先に落とさない）
     */
    public function for(AiProvider $provider): DecisionDriver
    {
        $class = $this->classFor($provider);

        if ($class === null) {
            throw new \RuntimeException($provider->label().' の判定ドライバは未登録です');
        }

        return $this->container->make($class);
    }

    /** 判定を実行できるプロバイダか。判定エンジンの候補を絞るのに使う */
    public function supports(AiProvider $provider): bool
    {
        return $this->classFor($provider) !== null;
    }

    /**
     * 判定専用のプロバイダを足すときはここに 1 行追加する。
     *
     * @return class-string<DecisionDriver>|null
     */
    private function classFor(AiProvider $provider): ?string
    {
        return match ($provider) {
            AiProvider::Jev => JevClient::class,
            // 生成用のプロバイダは判定の実行先にしない
            default => null,
        };
    }
}
