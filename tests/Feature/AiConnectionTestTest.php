<?php

namespace Tests\Feature;

use App\Enums\AiProvider;
use App\Enums\AiPurpose;
use App\Models\AiProviderConfig;
use App\Models\AiUsage;
use App\Services\Ai\AiPricing;
use App\Services\Ai\AiRunner;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Driver\AiDriver;
use App\Services\Ai\Driver\AiRequest;
use App\Services\Ai\Driver\AiResponse;
use App\Services\Ai\Driver\DriverRegistry;
use App\Services\Ai\Driver\OpenAiDriver;
use App\Services\Ai\Driver\TokenUsage;
use App\Services\Ai\OutputValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WithApiKeys;
use Tests\TestCase;

/** OpenAI ドライバの差し替え。呼ばれた回数と応答を固定する（本物の API には飛ばさない） */
class FakeOpenAiDriver implements AiDriver
{
    public int $calls = 0;

    public function __construct(private readonly ?\Throwable $failure = null) {}

    public function run(AiRequest $request): AiResponse
    {
        $this->calls++;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new AiResponse(['ok' => true], new TokenUsage(input: 42, output: 3), AiProvider::OpenAi, $request->model);
    }
}

/**
 * 設定画面の「接続をテスト」（AI エンジン）。
 * 未設定は通信せずに日本語で切り分け、実行時の失敗は理由をそのまま出す。黙って何も言わない状態を作らない。
 *
 * 結果は flash 'aiTest' で画面へ渡る（HandleInertiaRequests が共有）。ここでは flash の中身を直接見る。
 */
class AiConnectionTestTest extends TestCase
{
    use RefreshDatabase;
    use WithApiKeys;

    /** TestCase は AiRunner を ForbiddenAiRunner に束縛しているため、OpenAI ドライバだけ差し替えた本物を束縛する */
    private function bindDriver(FakeOpenAiDriver $driver): void
    {
        $this->app->instance(OpenAiDriver::class, $driver);
        $this->app->instance(AiRunner::class, new AiRunner(
            $this->app->make(AiSettings::class),
            $this->app->make(DriverRegistry::class),
            new OutputValidator,
            $this->app->make(AiPricing::class),
        ));
    }

    /** キーは設定ファイル側、モデルは DB 側。両方そろった状態を作る */
    private function registerOpenAi(?string $modelLight = 'gpt-5-mini'): void
    {
        $this->setApiKey(AiProvider::OpenAi, 'sk-test');
        AiProviderConfig::create(['provider' => AiProvider::OpenAi, 'model_light' => $modelLight]);
    }

    /**
     * @return array{provider: string, ok: bool, message: string}
     */
    private function flashedResult(): array
    {
        $result = session('aiTest');
        $this->assertIsArray($result, 'flash aiTest が無い');

        return $result;
    }

    public function test_繋がればモデル名と消費トークンを添えて返す(): void
    {
        $this->registerOpenAi();
        $driver = new FakeOpenAiDriver;
        $this->bindDriver($driver);

        $this->post(route('ai-provider.test', 'openai'))->assertRedirect()->assertSessionHas('aiTest');

        $this->assertSame(1, $driver->calls);
        $result = $this->flashedResult();
        $this->assertSame('openai', $result['provider']);
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('gpt-5-mini', $result['message']);
        $this->assertStringContainsString('入力 42 / 出力 3', $result['message']);

        $usage = AiUsage::sole();
        $this->assertSame(AiPurpose::ConnectionTest, $usage->purpose);
        $this->assertSame(AiProvider::OpenAi, $usage->provider);
        $this->assertTrue($usage->succeeded);
    }

    public function test_実行時の失敗は理由をそのまま画面に出す(): void
    {
        $this->registerOpenAi();
        $this->bindDriver(new FakeOpenAiDriver(new \RuntimeException('OpenAI API エラー（HTTP 401）: Incorrect API key provided')));

        $this->post(route('ai-provider.test', 'openai'))->assertRedirect();

        $result = $this->flashedResult();
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Incorrect API key provided', $result['message']);
        // 失敗も接続テストとして記録に残る
        $this->assertFalse(AiUsage::sole()->succeeded);
    }

    public function test_キー未設定ならドライバを呼ばずに変数名を示す(): void
    {
        $driver = new FakeOpenAiDriver;
        $this->bindDriver($driver);

        $this->post(route('ai-provider.test', 'openai'))->assertRedirect();

        $this->assertSame(0, $driver->calls);
        $this->assertSame(0, AiUsage::count());
        $result = $this->flashedResult();
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('.env の OPENAI_API_KEY が未設定です', $result['message']);
    }

    public function test_モデル未設定ならドライバを呼ばずにモデルの入力を促す(): void
    {
        $this->registerOpenAi(null);
        $driver = new FakeOpenAiDriver;
        $this->bindDriver($driver);

        $this->post(route('ai-provider.test', 'openai'))->assertRedirect();

        $this->assertSame(0, $driver->calls);
        $result = $this->flashedResult();
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('モデルが未設定', $result['message']);
    }

    public function test_存在しないプロバイダは404(): void
    {
        $this->post('/settings/ai/providers/foo/test')->assertNotFound();
    }

    public function test_判定専用の実行先はこの経路で接続テストできず404(): void
    {
        $this->setApiKey(AiProvider::Jev, 'ts-test');

        $this->post(route('ai-provider.test', 'jev'))->assertNotFound();
    }
}
