<?php

namespace Tests\Support;

use App\Enums\AiProvider;

/**
 * API キーは .env（config('ai.providers.*.api_key')）が唯一の置き場所なので、
 * テストでは config を差し替えて「設定済み / 未設定」を作る。
 */
trait WithApiKeys
{
    /** null / 空文字を渡すと「未設定」になる */
    protected function setApiKey(AiProvider $provider, ?string $key): void
    {
        config()->set("ai.providers.{$provider->value}.api_key", $key);
    }
}
