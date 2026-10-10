<?php

namespace Tests\Feature;

use App\Enums\AiProvider;
use App\Enums\AiPurpose;
use App\Enums\AiTier;
use App\Models\AiProviderConfig;
use App\Models\AiSetting;
use App\Models\AiUsage;
use App\Models\Company;
use App\Services\Ai\AiOutputMismatchException;
use App\Services\Ai\AiPricing;
use App\Services\Ai\AiRunner;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Driver\AiDriver;
use App\Services\Ai\Driver\AiRequest;
use App\Services\Ai\Driver\AiRequestFailed;
use App\Services\Ai\Driver\AiResponse;
use App\Services\Ai\Driver\CodexDriver;
use App\Services\Ai\Driver\DriverRegistry;
use App\Services\Ai\Driver\OpenAiDriver;
use App\Services\Ai\Driver\TokenUsage;
use App\Services\Ai\OutputValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WithApiKeys;
use Tests\TestCase;

/** 固定の出力とトークン数を返す差し替え（本物の codex は起動しない） */
class RecordingFakeDriver implements AiDriver
{
    public ?AiRequest $lastRequest = null;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private readonly array $data, private readonly ?TokenUsage $tokens = null) {}

    public function run(AiRequest $request): AiResponse
    {
        $this->lastRequest = $request;

        return new AiResponse($this->data, $this->tokens, AiProvider::Codex, $request->model);
    }
}

/** 実行が例外で終わる差し替え（課金済みの失敗・接続失敗の両方を再現する） */
class ThrowingFakeDriver implements AiDriver
{
    public function __construct(private readonly \Throwable $exception) {}

    public function run(AiRequest $request): AiResponse
    {
        throw $this->exception;
    }
}

/**
 * AI 使用量の計測（AiRunner::record）の検証。
 * 「何にどれだけ使ったか」の記録が本当に書かれることを、ドライバだけ差し替えて確かめる。
 */
class AiRunnerRecordTest extends TestCase
{
    use RefreshDatabase;
    use WithApiKeys;

    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['ok'],
        'properties' => ['ok' => ['type' => 'boolean']],
    ];

    /**
     * TestCase は AiRunner を ForbiddenAiRunner に束縛しているため、本物を直接組み立てる。
     *
     * @param  class-string  $bind  差し替える Driver。DriverRegistry がこのクラスをコンテナから解決する
     */
    private function runner(AiDriver $driver, string $bind = CodexDriver::class): AiRunner
    {
        $this->app->instance($bind, $driver);

        return new AiRunner(
            $this->app->make(AiSettings::class),
            $this->app->make(DriverRegistry::class),
            new OutputValidator,
            new AiPricing,
        );
    }

    private function setCodexModels(?string $analysis, ?string $light): void
    {
        AiProviderConfig::where('provider', 'codex')->sole()
            ->update(['model_analysis' => $analysis, 'model_light' => $light]);
    }

    public function test_成功した実行が実行先とトークン数つきで記録される(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $this->setCodexModels('gpt-test', null);
        $tokens = new TokenUsage(input: 1200, cached: 200, cacheWrite: null, output: 300, reasoning: 80, raw: ['input_tokens' => 1200]);
        $driver = new RecordingFakeDriver(['ok' => true], $tokens);

        $result = $this->runner($driver)->runWithSchema(
            self::SCHEMA,
            'プロンプト本文',
            AiTier::Analysis,
            AiPurpose::Analysis,
            'p1',
            $company->id,
        );

        $this->assertSame(['ok' => true], $result);
        // モデルは呼び出し元ではなく ai_provider_configs の codex 行から解決される
        $this->assertSame('gpt-test', $driver->lastRequest->model);
        $this->assertSame((int) config('services.codex.timeout'), $driver->lastRequest->timeout);

        $usage = AiUsage::sole();
        $this->assertSame(AiProvider::Codex, $usage->provider);
        $this->assertSame(AiPurpose::Analysis, $usage->purpose);
        $this->assertSame('gpt-test', $usage->model);
        $this->assertSame('p1', $usage->detail);
        $this->assertSame($company->id, $usage->company_id);
        $this->assertSame(mb_strlen('プロンプト本文'), $usage->prompt_chars);
        $this->assertSame(mb_strlen(json_encode(['ok' => true])), $usage->output_chars);
        $this->assertSame(1200, $usage->input_tokens);
        $this->assertSame(200, $usage->cached_input_tokens);
        $this->assertNull($usage->cache_write_input_tokens);
        $this->assertSame(300, $usage->output_tokens);
        $this->assertSame(80, $usage->reasoning_tokens);
        $this->assertSame(['input_tokens' => 1200], $usage->usage_raw);
        // 単価表を入れるまで費用は null（0 で埋めない）
        $this->assertNull($usage->cost_micro_usd);
        $this->assertTrue($usage->succeeded);
        $this->assertNull($usage->error);
    }

    public function test_単価表のあるapi実行は推定費用つきで記録される(): void
    {
        // codex はサブスクで単価表を持たないため、費用が入ることは API の実行先で確かめる
        config()->set('ai.pricing.openai', ['gpt-test' => ['input' => 4.00, 'cached_input' => 0.40, 'output' => 20.00]]);
        AiProviderConfig::create([
            'provider' => AiProvider::OpenAi,
            'model_light' => 'gpt-test',
        ]);
        $this->setApiKey(AiProvider::OpenAi, 'sk-test');
        AiSetting::sole()->update(['light_provider' => AiProvider::OpenAi]);

        $driver = new RecordingFakeDriver(['ok' => true], new TokenUsage(input: 1000, cached: 200, output: 50));

        $this->runner($driver, OpenAiDriver::class)
            ->runWithSchema(self::SCHEMA, 'プロンプト本文', AiTier::Light, AiPurpose::ReviewSplit);

        $usage = AiUsage::sole();
        $this->assertSame(AiProvider::OpenAi, $usage->provider);
        $this->assertSame('gpt-test', $usage->model);
        // (1000 − 200) × 4.00 + 200 × 0.40 + 50 × 20.00 = 3200 + 80 + 1000
        $this->assertSame(4280, $usage->cost_micro_usd);
    }

    public function test_単価表に無いモデルの実行は費用をnullで記録する(): void
    {
        config()->set('ai.pricing.openai', ['gpt-test' => ['input' => 4.00, 'cached_input' => 0.40, 'output' => 20.00]]);
        AiProviderConfig::create([
            'provider' => AiProvider::OpenAi,
            'model_light' => 'gpt-unknown',
        ]);
        $this->setApiKey(AiProvider::OpenAi, 'sk-test');
        AiSetting::sole()->update(['light_provider' => AiProvider::OpenAi]);

        $driver = new RecordingFakeDriver(['ok' => true], new TokenUsage(input: 1000, output: 50));

        $this->runner($driver, OpenAiDriver::class)
            ->runWithSchema(self::SCHEMA, 'プロンプト本文', AiTier::Light, AiPurpose::ReviewSplit);

        // 単価が無いのに 0 で埋めると「無料だった」と誤読させる
        $this->assertNull(AiUsage::sole()->cost_micro_usd);
    }

    public function test_単価表が壊れていても実行の記録は残り費用だけnullになる(): void
    {
        // 単価表の書き損じ（output の単価が無い）。費用が出せないだけで、実行の記録ごと消えてはいけない
        config()->set('ai.pricing.openai', ['gpt-test' => ['input' => 4.00, 'cached_input' => 0.40]]);
        AiProviderConfig::create([
            'provider' => AiProvider::OpenAi,
            'model_light' => 'gpt-test',
        ]);
        $this->setApiKey(AiProvider::OpenAi, 'sk-test');
        AiSetting::sole()->update(['light_provider' => AiProvider::OpenAi]);

        $driver = new RecordingFakeDriver(['ok' => true], new TokenUsage(input: 1000, output: 50));

        $this->runner($driver, OpenAiDriver::class)
            ->runWithSchema(self::SCHEMA, 'プロンプト本文', AiTier::Light, AiPurpose::ReviewSplit);

        $usage = AiUsage::sole();
        $this->assertNull($usage->cost_micro_usd, '費用は出せないので null');
        $this->assertTrue($usage->succeeded, '実行自体は成功として残る');
        $this->assertSame(1000, $usage->input_tokens);
    }

    public function test_トークンが取れない実行はnullで記録される(): void
    {
        // migration は開発機の .env（CODEX_MODEL）を codex 行にコピーするため、未設定の状態を明示して作る
        $this->setCodexModels(null, null);
        $this->runner(new RecordingFakeDriver(['ok' => true]))
            ->runWithSchema(self::SCHEMA, 'プロンプト本文', AiTier::Light, AiPurpose::ReviewSplit);

        $usage = AiUsage::sole();
        $this->assertNull($usage->model, 'codex 行のモデルが空なら既定モデル（null）で記録する');
        $this->assertNull($usage->input_tokens);
        $this->assertNull($usage->output_tokens);
        $this->assertNull($usage->usage_raw);
    }

    public function test_課金済みの失敗はトークン数つきで記録され例外は再送出される(): void
    {
        $tokens = new TokenUsage(input: 500, output: 4000, raw: ['reason' => 'max_output_tokens']);
        $failure = new AiRequestFailed('出力上限で打ち切られました', AiProvider::Codex, null, $tokens);

        try {
            $this->runner(new ThrowingFakeDriver($failure))
                ->runWithSchema(self::SCHEMA, 'プロンプト本文', AiTier::Light, AiPurpose::ReviewSplit);
            $this->fail('例外が再送出されるはず');
        } catch (AiRequestFailed $e) {
            $this->assertSame($failure, $e);
        }

        $usage = AiUsage::sole();
        $this->assertSame(AiPurpose::ReviewSplit, $usage->purpose);
        $this->assertFalse($usage->succeeded);
        $this->assertSame('出力上限で打ち切られました', $usage->error);
        $this->assertSame(500, $usage->input_tokens);
        $this->assertSame(4000, $usage->output_tokens);
        $this->assertSame(['reason' => 'max_output_tokens'], $usage->usage_raw);
        $this->assertSame(0, $usage->output_chars);
    }

    public function test_接続失敗など課金されない失敗もトークンなしで記録される(): void
    {
        try {
            $this->runner(new ThrowingFakeDriver(new \RuntimeException('codex timed out')))
                ->runWithSchema(self::SCHEMA, 'プロンプト本文', AiTier::Light, AiPurpose::ReviewSplit);
            $this->fail('例外が再送出されるはず');
        } catch (\RuntimeException $e) {
            $this->assertSame('codex timed out', $e->getMessage());
        }

        // 失敗した実行もサブスクを消費しうるため、記録は残す
        $usage = AiUsage::sole();
        $this->assertFalse($usage->succeeded);
        $this->assertSame('codex timed out', $usage->error);
        $this->assertNull($usage->input_tokens);
        $this->assertNull($usage->usage_raw);
    }

    public function test_スキーマに合わない応答は例外になり失敗として記録される(): void
    {
        $tokens = new TokenUsage(input: 10, output: 5);

        try {
            $this->runner(new RecordingFakeDriver(['ok' => 'yes'], $tokens))
                ->runWithSchema(self::SCHEMA, 'プロンプト本文', AiTier::Light, AiPurpose::ClipClassify);
            $this->fail('スキーマ不一致は例外になるはず');
        } catch (AiOutputMismatchException $e) {
            $this->assertStringContainsString('/ok', $e->getMessage());
        }

        // 応答は受け取っている（課金済み）ので、トークン数は残す
        $usage = AiUsage::sole();
        $this->assertFalse($usage->succeeded);
        $this->assertStringContainsString('正規スキーマ', $usage->error);
        $this->assertSame(10, $usage->input_tokens);
    }

    public function test_用途を渡さない実行は記録しない(): void
    {
        $this->runner(new RecordingFakeDriver(['ok' => true]))
            ->runWithSchema(self::SCHEMA, 'プロンプト本文', AiTier::Light);

        $this->assertSame(0, AiUsage::count());
    }

    public function test_空のプロンプトは実行先に渡さず例外にする(): void
    {
        $driver = new RecordingFakeDriver(['ok' => true]);

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->runner($driver)->runWithSchema(self::SCHEMA, '   ', AiTier::Light, AiPurpose::ClipClassify);
        } finally {
            $this->assertNull($driver->lastRequest, 'ドライバは呼ばれない');
            $this->assertSame(0, AiUsage::count(), '実行していないので記録もしない');
        }
    }

    public function test_接続テストは抽出判定用モデルで1回実行し接続テストとして記録する(): void
    {
        $this->setCodexModels('gpt-analysis', 'gpt-light');
        $driver = new RecordingFakeDriver(['ok' => true]);

        $recorded = $this->runner($driver)->probe(AiProvider::Codex);

        $this->assertSame('gpt-light', $driver->lastRequest->model);
        $this->assertSame(['ok'], $driver->lastRequest->schema['required']);

        $usage = AiUsage::sole();
        // 戻り値は今回の実行の記録そのもの（呼び出し元が過去の記録を引き直さずに済む）
        $this->assertSame($usage->id, $recorded?->id);
        $this->assertSame(AiPurpose::ConnectionTest, $usage->purpose);
        $this->assertSame(AiProvider::Codex, $usage->provider);
        $this->assertSame('gpt-light', $usage->model);
        $this->assertTrue($usage->succeeded);
    }

    public function test_接続テストは抽出判定用モデルが空なら分析用モデルを使う(): void
    {
        $this->setCodexModels('gpt-analysis', null);
        $driver = new RecordingFakeDriver(['ok' => true]);

        $this->runner($driver)->probe(AiProvider::Codex);

        $this->assertSame('gpt-analysis', $driver->lastRequest->model);
        $this->assertSame('gpt-analysis', AiUsage::sole()->model);
    }
}
