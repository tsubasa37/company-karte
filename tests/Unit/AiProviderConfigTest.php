<?php

namespace Tests\Unit;

use App\Enums\AiProvider;
use App\Models\AiProviderConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * プロバイダ設定の器。モデルと固有オプションだけを持ち、API キーは持たない
 * （キーの置き場所は .env の1本だけ。置き場所が2つある状態を作らない）。
 */
class AiProviderConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_apiキーの列そのものを持たない(): void
    {
        $this->assertFalse(Schema::hasColumn('ai_provider_configs', 'api_key'));
    }

    public function test_apiキーは書き込めず配列化にも出ない(): void
    {
        // fillable に無いので、送られてきても静かに保存されない
        $config = AiProviderConfig::create(['provider' => AiProvider::Gemini, 'api_key' => 'AIza-secret']);

        $this->assertArrayNotHasKey('api_key', $config->toArray());
        $this->assertArrayNotHasKey('api_key', $config->fresh()->toArray());
        $this->assertStringNotContainsString('AIza-secret', json_encode($config->fresh()));
    }

    public function test_optionsは配列のまま往復する(): void
    {
        $options = ['analysis' => ['reasoning_effort' => 'low'], 'light' => ['reasoning_effort' => null]];
        AiProviderConfig::create(['provider' => AiProvider::OpenAi, 'options' => $options]);

        $this->assertSame($options, AiProviderConfig::where('provider', 'openai')->sole()->options);
    }
}
