<?php

namespace Tests\Unit;

use App\Enums\AiProvider;
use App\Models\AiProviderConfig;
use App\Services\Ai\Driver\AiRequest;
use App\Services\Ai\Driver\AiRequestFailed;
use App\Services\Ai\Driver\AnthropicDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WithApiKeys;
use Tests\TestCase;

/**
 * Anthropic Messages API ドライバ。本物には一切飛ばさない（Http::fake + preventStrayRequests）。
 * 「リクエストの必須項目が入ること」「usage が共通の意味に正規化されること」「失敗が例外になること」が要点。
 *
 * 応答の形は公式リファレンスに合わせる（usage の各欄・stop_reason・エラー本文）。
 * 確認 2026-09-03: https://platform.claude.com/docs/en/api/messages
 * ・ https://platform.claude.com/docs/en/build-with-claude/structured-outputs
 * ・ https://platform.claude.com/docs/en/build-with-claude/thinking ・ /docs/en/api/errors
 */
class AnthropicDriverTest extends TestCase
{
    use RefreshDatabase;
    use WithApiKeys;

    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['ok'],
        'properties' => ['ok' => ['type' => 'boolean']],
    ];

    /** input_tokens はキャッシュ分を含まない（総入力 = 2095 + 2051 + 1000）。thinking_tokens は output の内数 */
    private const USAGE = [
        'input_tokens' => 2095,
        'cache_read_input_tokens' => 2051,
        'cache_creation_input_tokens' => 1000,
        'output_tokens' => 503,
        'output_tokens_details' => ['thinking_tokens' => 120],
        'service_tier' => 'standard',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        // 接続先・版・上限は開発者の環境変数に左右されないようテスト側で固定する
        config([
            'ai.providers.anthropic.base_url' => 'https://api.anthropic.com/v1',
            'ai.providers.anthropic.version' => '2023-06-01',
            'ai.providers.anthropic.max_tokens' => 32000,
        ]);
        AiProviderConfig::create(['provider' => AiProvider::Anthropic]);
        $this->setApiKey(AiProvider::Anthropic, 'sk-ant-api03-testkey');
    }

    /**
     * @param  array<string, mixed>  $options
     */
    /** キー読み取り役を受け取るのでコンテナから解決する */
    private function driver(): AnthropicDriver
    {
        return $this->app->make(AnthropicDriver::class);
    }

    private function request(array $options = [], ?string $model = 'claude-sonnet-5', int $timeout = 180): AiRequest
    {
        return new AiRequest(self::SCHEMA, '判定してください', $model, $options, $timeout);
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     * @param  array<string, mixed>|null  $usage
     * @return array<string, mixed>
     */
    private function message(array $content, string $stopReason = 'end_turn', ?array $usage = self::USAGE): array
    {
        return [
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5',
            'content' => $content,
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => $usage,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function fake(array $body, int $status = 200): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($body, $status)]);
    }

    public function test_成功した応答のjsonとusageを共通の意味に正規化して返す(): void
    {
        $this->fake($this->message([['type' => 'text', 'text' => '{"ok": true}']]));

        $response = $this->driver()->run($this->request());

        $this->assertSame(['ok' => true], $response->data);
        $this->assertSame(AiProvider::Anthropic, $response->provider);
        $this->assertSame('claude-sonnet-5', $response->model);
        // 総入力は input_tokens + cache_read + cache_creation の3値合算
        $this->assertSame(5146, $response->tokens->input);
        $this->assertSame(2051, $response->tokens->cached);
        $this->assertSame(1000, $response->tokens->cacheWrite);
        // thinking_tokens は output_tokens の内数なので加算しない
        $this->assertSame(503, $response->tokens->output);
        $this->assertSame(120, $response->tokens->reasoning);
        $this->assertSame(self::USAGE, $response->tokens->raw);
    }

    public function test_思考ブロックは結果のjsonに混ぜない(): void
    {
        $this->fake($this->message([
            ['type' => 'thinking', 'thinking' => 'まず条件を確認する', 'signature' => 'WaUjzkypQ2mUEVM36O2Txu'],
            ['type' => 'redacted_thinking', 'data' => 'EmwKAhgBEgy3va3'],
            ['type' => 'text', 'text' => '{"ok": true}'],
        ]));

        $this->assertSame(['ok' => true], $this->driver()->run($this->request())->data);
    }

    public function test_usageに無い欄はnullになり0で埋めない(): void
    {
        $this->fake($this->message(
            [['type' => 'text', 'text' => '{"ok": true}']],
            usage: ['input_tokens' => 10, 'output_tokens' => 3],
        ));

        $tokens = $this->driver()->run($this->request())->tokens;

        $this->assertSame(10, $tokens->input);
        $this->assertNull($tokens->cached);
        $this->assertNull($tokens->cacheWrite);
        $this->assertSame(3, $tokens->output);
        $this->assertNull($tokens->reasoning);
    }

    public function test_リクエストには構造化出力とバージョンヘッダとmax_tokensが入る(): void
    {
        $this->fake($this->message([['type' => 'text', 'text' => '{"ok": true}']]));

        $this->driver()->run($this->request([], 'claude-opus-5', 90));

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('x-api-key', 'sk-ant-api03-testkey')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                // GA 済みなのでベータヘッダは送らない
                && ! $request->hasHeader('anthropic-beta')
                && $body['model'] === 'claude-opus-5'
                && $body['max_tokens'] === 32000
                && $body['messages'] === [['role' => 'user', 'content' => '判定してください']]
                // ツール強制ではなく output_config.format（name / strict は無い）
                && $body['output_config'] === ['format' => ['type' => 'json_schema', 'schema' => self::SCHEMA]]
                && ! array_key_exists('thinking', $body);
        });
    }

    public function test_adaptiveはthinkingとoutput_config_effortに分けて送る(): void
    {
        $this->fake($this->message([['type' => 'text', 'text' => '{"ok": true}']]));

        $this->driver()->run($this->request(['thinking_mode' => 'adaptive', 'effort' => 'high']));

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            // effort は thinking の中ではなく output_config 側（format と同居する）
            return $body['thinking'] === ['type' => 'adaptive']
                && $body['output_config']['effort'] === 'high'
                && $body['output_config']['format']['type'] === 'json_schema';
        });
    }

    public function test_adaptiveでeffort未設定ならeffortを送らない(): void
    {
        $this->fake($this->message([['type' => 'text', 'text' => '{"ok": true}']]));

        $this->driver()->run($this->request(['thinking_mode' => 'adaptive', 'effort' => '']));

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $body['thinking'] === ['type' => 'adaptive'] && ! array_key_exists('effort', $body['output_config']);
        });
    }

    public function test_enabledはbudget_tokens付きのthinkingを送る(): void
    {
        $this->fake($this->message([['type' => 'text', 'text' => '{"ok": true}']]));

        $this->driver()->run($this->request(['thinking_mode' => 'enabled', 'budget_tokens' => '4096']));

        Http::assertSent(fn (Request $request) => $request->data()['thinking'] === ['type' => 'enabled', 'budget_tokens' => 4096]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function 噛み合わない推論の設定(): array
    {
        return [
            'モード未設定で effort' => [['effort' => 'high'], 'thinking_mode を選んだときだけ'],
            'モード未設定で budget' => [['budget_tokens' => '4096'], 'thinking_mode を選んだときだけ'],
            'adaptive に budget' => [['thinking_mode' => 'adaptive', 'budget_tokens' => '4096'], 'enabled（4.6 以前）専用'],
            'enabled に effort' => [['thinking_mode' => 'enabled', 'effort' => 'high'], 'adaptive（4.6 以降）専用'],
            'enabled で budget 未設定' => [['thinking_mode' => 'enabled'], 'budget_tokens（整数）の指定が必要'],
            '下限未満の budget' => [['thinking_mode' => 'enabled', 'budget_tokens' => '1023'], '1024以上'],
            'max_tokens 以上の budget' => [['thinking_mode' => 'enabled', 'budget_tokens' => '32000'], 'max_tokens（32000）未満'],
            '知らないモード' => [['thinking_mode' => 'ultra'], 'adaptive か enabled'],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[DataProvider('噛み合わない推論の設定')]
    public function test_噛み合わない推論の設定は送信せずに例外にする(array $options, string $expected): void
    {
        Http::fake();

        try {
            $this->driver()->run($this->request($options));
            $this->fail('例外になるはず');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_timeoutはリクエストの値がそのまま渡る(): void
    {
        $seen = null;
        Http::fake(['api.anthropic.com/*' => function (Request $request, array $options) use (&$seen) {
            $seen = $options['timeout'];

            return Http::response($this->message([['type' => 'text', 'text' => '{"ok": true}']]));
        }]);

        $this->driver()->run($this->request([], 'claude-sonnet-5', 45));

        $this->assertSame(45, $seen);
    }

    public function test_refusalはusage付きの例外にする(): void
    {
        $this->fake($this->message([], 'refusal') + [
            'stop_details' => ['type' => 'refusal', 'category' => 'general_harms', 'explanation' => 'この内容には対応できません'],
        ]);

        try {
            $this->driver()->run($this->request());
            $this->fail('AiRequestFailed になるはず');
        } catch (AiRequestFailed $e) {
            $this->assertStringContainsString('拒否', $e->getMessage());
            $this->assertStringContainsString('この内容には対応できません', $e->getMessage());
            $this->assertSame(AiProvider::Anthropic, $e->provider);
            $this->assertSame('claude-sonnet-5', $e->model);
            // 拒否でも課金されているため、トークン数は記録に渡す
            $this->assertSame(5146, $e->usage->input);
            $this->assertSame(503, $e->usage->output);
        }
    }

    public function test_出力上限での打ち切りはusage付きの例外にする(): void
    {
        $this->fake($this->message([['type' => 'text', 'text' => '{"ok":']], 'max_tokens'));

        try {
            $this->driver()->run($this->request());
            $this->fail('AiRequestFailed になるはず');
        } catch (AiRequestFailed $e) {
            $this->assertStringContainsString('max_tokens', $e->getMessage());
            $this->assertSame(5146, $e->usage->input);
        }
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: string}>
     */
    public static function httpエラー(): array
    {
        return [
            '401' => [401, 'authentication_error', 'invalid x-api-key: sk-ant-api03-testkey'],
            '429' => [429, 'rate_limit_error', 'Number of request tokens has exceeded your rate limit'],
            '529' => [529, 'overloaded_error', 'Overloaded'],
        ];
    }

    #[DataProvider('httpエラー')]
    public function test_httpエラーはerror_typeとmessageだけを載せキーの断片を伏せる(int $status, string $type, string $message): void
    {
        $this->fake([
            'type' => 'error',
            'error' => ['type' => $type, 'message' => $message],
            'request_id' => 'req_011CSHoEeqs5C35K2UUqR7Fy',
        ], $status);

        try {
            $this->driver()->run($this->request());
            $this->fail('例外になるはず');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(AiRequestFailed::class, $e, '応答を得られていないので usage 付きの失敗にしない');
            $this->assertStringContainsString("HTTP {$status} {$type}", $e->getMessage());
            $this->assertStringNotContainsString('sk-ant-api03-testkey', $e->getMessage());
            $this->assertStringNotContainsString('req_011CSHoEeqs', $e->getMessage(), 'error.message 以外の本文は載せない');
        }
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function 但し書きの要るステータス(): array
    {
        return ['429' => [429, 'レート上限'], '529' => [529, '混雑']];
    }

    /** Http::fake は後から呼んでも先に登録した stub が勝つため、1テスト1ステータスにする */
    #[DataProvider('但し書きの要るステータス')]
    public function test_429と529は原因が分かる日本語の但し書きを付ける(int $status, string $hint): void
    {
        $this->fake(['type' => 'error', 'error' => ['type' => 'x', 'message' => 'y']], $status);

        try {
            $this->driver()->run($this->request());
            $this->fail('例外になるはず');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($hint, $e->getMessage());
        }
    }

    public function test_2xxでも本文がjsonでなければ診断できる例外にする(): void
    {
        // base_url をプロキシ等に向けると起こりうる。型エラー（TypeError）を analysis_steps.error に残さない
        Http::fake(['api.anthropic.com/*' => Http::response('<html><body>502 Bad Gateway</body></html>', 200, ['Content-Type' => 'text/html'])]);

        try {
            $this->driver()->run($this->request());
            $this->fail('例外になるはず');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('応答が JSON ではありません', $e->getMessage());
            $this->assertStringContainsString('base_url', $e->getMessage(), '復旧の手掛かり（接続先）を出す');
            $this->assertStringContainsString('502 Bad Gateway', $e->getMessage());
        }
    }

    public function test_テキストがjsonでなければusage付きの例外にする(): void
    {
        $this->fake($this->message([['type' => 'text', 'text' => 'すみません、判定できません']]));

        try {
            $this->driver()->run($this->request());
            $this->fail('AiRequestFailed になるはず');
        } catch (AiRequestFailed $e) {
            $this->assertStringContainsString('JSON ではありません', $e->getMessage());
            $this->assertSame(503, $e->usage->output);
        }
    }

    public function test_形の違う応答でもトークンを残した例外にする(): void
    {
        // 中継サーバや将来の版が別の形を返しても、警告由来の ErrorException にせず
        // AiRequestFailed にする（課金済みのトークンが ai_usages に残らなくなるため）
        $this->fake([
            'id' => 'msg_1',
            'type' => 'message',
            'content' => '{"ok": true}',
            'stop_reason' => 'end_turn',
            'usage' => self::USAGE,
        ]);

        try {
            $this->driver()->run($this->request());
            $this->fail('AiRequestFailed になるはず');
        } catch (AiRequestFailed $e) {
            $this->assertStringContainsString('テキスト出力', $e->getMessage());
            $this->assertSame(5146, $e->usage->input);
        }
    }

    public function test_接続失敗は例外にする(): void
    {
        Http::fake(['api.anthropic.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Anthropic API に接続できませんでした: cURL error 28');

        $this->driver()->run($this->request());
    }

    public function test_キー未登録なら送信せずに例外にする(): void
    {
        $this->setApiKey(AiProvider::Anthropic, null);
        Http::fake();

        try {
            $this->driver()->run($this->request());
            $this->fail('例外になるはず');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('.env の ANTHROPIC_API_KEY が未設定', $e->getMessage());
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
