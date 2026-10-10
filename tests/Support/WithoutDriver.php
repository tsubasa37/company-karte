<?php

namespace Tests\Support;

use App\Enums\AiProvider;
use App\Services\Ai\Driver\DriverRegistry;
use Mockery;

/**
 * 「ドライバの無い実行先は候補に出さない・選ばせない」不変条件を検証するための差し替え。
 *
 * 実在の4プロバイダには全てドライバがあるため、この状態は本物では再現できない。
 * DriverRegistry::supports() だけを差し替えて、次にプロバイダを足したときの挙動を先に固定しておく。
 */
trait WithoutDriver
{
    protected function withoutDriverFor(AiProvider $unsupported): void
    {
        $registry = Mockery::mock(DriverRegistry::class);
        $registry->shouldReceive('supports')->andReturnUsing(fn (AiProvider $provider) => $provider !== $unsupported);

        $this->app->instance(DriverRegistry::class, $registry);
    }
}
