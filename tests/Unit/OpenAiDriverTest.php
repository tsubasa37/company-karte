<?php

namespace Tests\Unit;

use App\Enums\AiProvider;
use App\Models\AiProviderConfig;
use App\Services\Ai\Driver\AiRequest;
use App\Services\Ai\Driver\AiRequestFailed;
use App\Services\Ai\Driver\OpenAiDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\WithApiKeys;
use Tests\TestCase;

/**
 * OpenAI Responses API ドライバ。本物には一切飛ばさない（Http::fake + preventStrayRequests）。
 * 「リクエストの必須項目が入ること」「usage が共通の意味に正規化されること」「失敗が例外になること」が要点。
 */
class OpenAiDriverTest extends TestCase
{
    use RefreshDatabase;
    use WithApiKeys;

    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['ok'],
        'properties' => ['ok' => ['type' => 'boolean']],
    ];

    private const USAGE = [
        'input_tokens' => 120,
        'input_tokens_details' => ['cached_tokens' => 20, 'cache_write_tokens' => 5],
        'output_tokens' => 40,
        'output_tokens_details' => ['reasoning_tokens' => 15],
        'total_tokens' => 160,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        AiProviderConfig::create(['provider' => AiProvider::OpenAi]);
        $this->setApiKey(AiProvider::OpenAi, 'sk-test-key-123');
    }

    /**
     * @param  array<string, mixed>  $options
     */
    /** キー読み取り役を受け取るのでコンテナから解決する */
    private function driver(): OpenAiDriver
    {
        return $this->app->make(OpenAiDriver::class);
    }

    private function request(array $options = [], ?string $model = 'gpt-5-mini', int $timeout = 180): AiRequest
    {
        return new AiRequest(self::SCHEMA, '判定してください', $model, $options, $timeout);
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private function completed(array $content, ?array $usage = self::USAGE): array
    {
        return [
            'id' => 'resp_1',
            'status' => 'completed',
            'model' => 'gpt-5-mini-2026-01-01',
            'output' => [
                ['type' => 'reasoning', 'summary' => []],
                ['type' => 'message', 'role' => 'assistant', 'content' => $content],
            ],
            'usage' => $usage,
        ];
    }

    public function test_成功した応答のjsonとusageを共通の意味に正規化して返す(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->completed([
            ['type' => 'output_text', 'text' => '{"ok": true}'],
        ]))]);

        $response = $this->driver()->run($this->request());

        $this->assertSame(['ok' => true], $response->data);
        $this->assertSame(AiProvider::OpenAi, $response->provider);
        $this->assertSame('gpt-5-mini', $response->model);
        $this->assertSame(120, $response->tokens->input);
        $this->assertSame(20, $response->tokens->cached);
        $this->assertSame(5, $response->tokens->cacheWrite);
        $this->assertSame(40, $response->tokens->output);
        $this->assertSame(15, $response->tokens->reasoning);
        $this->assertSame(self::USAGE, $response->tokens->raw);
    }

    public function test_usageに無い欄はnullになり0で埋めない(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->completed(
            [['type' => 'output_text', 'text' => '{"ok": true}']],
            ['input_tokens' => 10, 'output_tokens' => 3],
        ))]);

        $tokens = $this->driver()->run($this->request())->tokens;

        $this->assertSame(10, $tokens->input);
        $this->assertNull($tokens->cached);
        $this->assertNull($tokens->cacheWrite);
        $this->assertSame(3, $tokens->output);
        $this->assertNull($tokens->reasoning);
    }

    public function test_リクエストにはstrictスキーマとstore_falseと認証ヘッダが入る(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->completed([
            ['type' => 'output_text', 'text' => '{"ok": true}'],
        ]))]);

        $this->driver()->run($this->request(['reasoning_effort' => 'low'], 'gpt-5', 90));

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://api.openai.com/v1/responses'
                && $request->hasHeader('Authorization', 'Bearer sk-test-key-123')
                && $body['model'] === 'gpt-5'
                && $body['input'] === [['role' => 'user', 'content' => '判定してください']]
                && $body['text']['format']['type'] === 'json_schema'
                && $body['text']['format']['name'] === 'result'
                && $body['text']['format']['strict'] === true
                && $body['text']['format']['schema'] === self::SCHEMA
                && $body['store'] === false
                // 出力上限を送らないと長い生成が timeout で切れ、usage を取れないまま課金だけが残る
                && $body['max_output_tokens'] === config('ai.providers.openai.max_output_tokens')
                && $body['reasoning'] === ['effort' => 'low'];
        });
    }

    public function test_出力上限が設定されていなければ送信せずに例外にする(): void
    {
        Http::fake();
        config(['ai.providers.openai.max_output_tokens' => 0]);

        $this->expectExceptionMessage('ai.providers.openai.max_output_tokens');

        try {
            $this->driver()->run($this->request());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_複数のoutput_textパートは上書きせず連結する(): void
    {
        // 長い出力は複数パートに分かれうる。上書きすると JSON が途中から始まって壊れる
        Http::fake(['api.openai.com/*' => Http::response($this->completed([
            ['type' => 'output_text', 'text' => '{"ok":'],
            ['type' => 'output_text', 'text' => ' true}'],
        ]))]);

        $this->assertSame(['ok' => true], $this->driver()->run($this->request())->data);
    }

    public function test_失敗した応答はerror_messageを文言に載せる(): void
    {
        // status=failed の理由は incomplete_details ではなく error.message に入る。捨てると原因が追えない
        Http::fake(['api.openai.com/*' => Http::response([
            'id' => 'resp_1',
            'status' => 'failed',
            'error' => ['code' => 'server_error', 'message' => 'The model produced an internal error'],
            'usage' => self::USAGE,
        ])]);

        try {
            $this->driver()->run($this->request());
            $this->fail('例外になるはず');
        } catch (AiRequestFailed $e) {
            $this->assertStringContainsString('failed', $e->getMessage());
            $this->assertStringContainsString('The model produced an internal error', $e->getMessage());
            // 課金済みの失敗なので usage は残す
            $this->assertSame(120, $e->usage->input);
        }
    }

    public function test_推論強度が未設定ならreasoningキーごと送らない(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->completed([
            ['type' => 'output_text', 'text' => '{"ok": true}'],
        ]))]);

        $this->driver()->run($this->request(['reasoning_effort' => '']));

        // 非対応モデルに送ると 400 になるため、鍵ごと落とす
        Http::assertSent(fn (Request $request) => ! array_key_exists('reasoning', $request->data()));
    }

    public function test_timeoutはリクエストの値がそのまま渡る(): void
    {
        $seen = null;
        Http::fake(['api.openai.com/*' => function (Request $request, array $options) use (&$seen) {
            $seen = $options['timeout'];

            return Http::response($this->completed([['type' => 'output_text', 'text' => '{"ok": true}']]));
        }]);

        $this->driver()->run($this->request([], 'gpt-5-mini', 45));

        $this->assertSame(45, $seen);
    }

    public function test_完了しなかった応答はusage付きの例外にする(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output' => [],
            'usage' => self::USAGE,
        ])]);

        try {
            $this->driver()->run($this->request());
            $this->fail('AiRequestFailed になるはず');
        } catch (AiRequestFailed $e) {
            $this->assertStringContainsString('max_output_tokens', $e->getMessage());
            $this->assertSame(AiProvider::OpenAi, $e->provider);
            $this->assertSame('gpt-5-mini', $e->model);
            // 打ち切りでも課金されているため、トークン数は記録に渡す
            $this->assertSame(120, $e->usage->input);
            $this->assertSame(40, $e->usage->output);
        }
    }

    public function test_refusalはusage付きの例外にする(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->completed([
            ['type' => 'refusal', 'refusal' => 'この内容には対応できません'],
        ]))]);

        try {
            $this->driver()->run($this->request());
            $this->fail('AiRequestFailed になるはず');
        } catch (AiRequestFailed $e) {
            $this->assertStringContainsString('拒否', $e->getMessage());
            $this->assertStringContainsString('この内容には対応できません', $e->getMessage());
            $this->assertSame(120, $e->usage->input);
        }
    }

    public function test_httpエラーはerror_messageだけを載せキーの断片を伏せる(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'error' => [
                'message' => 'Incorrect API key provided: sk-test-***-123. You can find your API key at https://platform.openai.com.',
                'type' => 'invalid_request_error',
                'code' => 'invalid_api_key',
            ],
        ], 401)]);

        try {
            $this->driver()->run($this->request());
            $this->fail('例外になるはず');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(AiRequestFailed::class, $e, '4xx は課金されないので usage 付きの失敗にしない');
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
            $this->assertStringContainsString('Incorrect API key provided', $e->getMessage());
            $this->assertStringNotContainsString('sk-test', $e->getMessage());
            $this->assertStringNotContainsString('invalid_request_error', $e->getMessage(), 'error.message 以外の本文は載せない');
        }
    }

    public function test_2xxでも本文がjsonでなければ診断できる例外にする(): void
    {
        // base_url をプロキシ等に向けると起こりうる。型エラー（TypeError）を analysis_steps.error に残さない
        Http::fake(['api.openai.com/*' => Http::response('<html><body>502 Bad Gateway</body></html>', 200, ['Content-Type' => 'text/html'])]);

        try {
            $this->driver()->run($this->request());
            $this->fail('例外になるはず');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('応答が JSON ではありません', $e->getMessage());
            $this->assertStringContainsString('base_url', $e->getMessage(), '復旧の手掛かり（接続先）を出す');
            $this->assertStringContainsString('502 Bad Gateway', $e->getMessage());
        }
    }

    public function test_形の違う応答でもトークンを残した例外にする(): void
    {
        // 中継サーバや将来の版が別の形を返しても、警告由来の ErrorException にせず
        // AiRequestFailed にする（課金済みのトークンが ai_usages に残らなくなるため）
        Http::fake(['api.openai.com/*' => Http::response([
            'id' => 'resp_1',
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => '{"ok": true}']],
            'usage' => self::USAGE,
        ])]);

        try {
            $this->driver()->run($this->request());
            $this->fail('AiRequestFailed になるはず');
        } catch (AiRequestFailed $e) {
            $this->assertStringContainsString('テキスト出力', $e->getMessage());
            $this->assertSame(120, $e->usage->input);
        }
    }

    public function test_接続失敗は例外にする(): void
    {
        Http::fake(['api.openai.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API に接続できませんでした: cURL error 28');

        $this->driver()->run($this->request());
    }

    public function test_キー未登録なら送信せずに例外にする(): void
    {
        $this->setApiKey(AiProvider::OpenAi, null);
        Http::fake();

        try {
            $this->driver()->run($this->request());
            $this->fail('例外になるはず');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('.env の OPENAI_API_KEY が未設定', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_モデル未指定なら送信せずに例外にする(): void
    {
        Http::fake();

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->driver()->run($this->request([], null));
        } finally {
            Http::assertNothingSent();
        }
    }
}
