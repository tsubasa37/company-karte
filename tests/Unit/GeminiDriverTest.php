<?php

namespace Tests\Unit;

use App\Enums\AiProvider;
use App\Models\AiProviderConfig;
use App\Services\Ai\Driver\AiRequest;
use App\Services\Ai\Driver\AiRequestFailed;
use App\Services\Ai\Driver\GeminiDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WithApiKeys;
use Tests\TestCase;

/**
 * Gemini generateContent ドライバ。本物には一切飛ばさない（Http::fake + preventStrayRequests）。
 * 「リクエストの必須項目が入ること」「usage が共通の意味に正規化されること」「失敗が例外になること」が要点。
 *
 * 応答の形は公式リファレンスに合わせる（usageMetadata の欄・FinishReason・BlockReason）。
 * 確認 2026-09-03: https://ai.google.dev/api/generate-content
 * ・ https://ai.google.dev/gemini-api/docs/generate-content/structured-output
 * ・ https://ai.google.dev/gemini-api/docs/generate-content/thinking
 */
class GeminiDriverTest extends TestCase
{
    use RefreshDatabase;
    use WithApiKeys;

    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['ok'],
        'properties' => ['ok' => ['type' => 'boolean']],
    ];

    /** thoughtsTokenCount は candidatesTokenCount の外数（料金は両者の和が出力扱い） */
    private const USAGE = [
        'promptTokenCount' => 120,
        'cachedContentTokenCount' => 20,
        'candidatesTokenCount' => 40,
        'thoughtsTokenCount' => 15,
        'totalTokenCount' => 175,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        AiProviderConfig::create(['provider' => AiProvider::Gemini]);
        $this->setApiKey(AiProvider::Gemini, 'AIzaSyTestKey123');
    }

    /**
     * @param  array<string, mixed>  $options
     */
    /** キー読み取り役を受け取るのでコンテナから解決する */
    private function driver(): GeminiDriver
    {
        return $this->app->make(GeminiDriver::class);
    }

    private function request(array $options = [], ?string $model = 'gemini-3.8-flash', int $timeout = 180): AiRequest
    {
        return new AiRequest(self::SCHEMA, '判定してください', $model, $options, $timeout);
    }

    /**
     * @param  array<int, array<string, mixed>>  $parts
     * @param  array<string, mixed>|null  $usage
     * @return array<string, mixed>
     */
    private function candidate(array $parts, string $finishReason = 'STOP', ?array $usage = self::USAGE): array
    {
        return [
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => $parts],
                'finishReason' => $finishReason,
                'index' => 0,
            ]],
            'usageMetadata' => $usage,
            'modelVersion' => 'gemini-3.8-flash',
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function fake(array $body, int $status = 200): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($body, $status)]);
    }

    public function test_成功した応答のjsonとusageを共通の意味に正規化して返す(): void
    {
        $this->fake($this->candidate([['text' => '{"ok": true}']]));

        $response = $this->driver()->run($this->request());

        $this->assertSame(['ok' => true], $response->data);
        $this->assertSame(AiProvider::Gemini, $response->provider);
        $this->assertSame('gemini-3.8-flash', $response->model);
        $this->assertSame(120, $response->tokens->input);
        $this->assertSame(20, $response->tokens->cached);
        // 明示キャッシュは保存時間で課金され、書き込みトークンの欄が無い
        $this->assertNull($response->tokens->cacheWrite);
        // 思考は candidates の外数なので、課金対象の全出力は 40 + 15
        $this->assertSame(55, $response->tokens->output);
        $this->assertSame(15, $response->tokens->reasoning);
        $this->assertSame(self::USAGE, $response->tokens->raw);
    }

    public function test_思考の要約パートは結果のjsonに混ぜない(): void
    {
        $this->fake($this->candidate([
            ['text' => 'まず条件を確認します', 'thought' => true],
            ['text' => '{"ok": true}'],
        ]));

        $this->assertSame(['ok' => true], $this->driver()->run($this->request())->data);
    }

    public function test_usageの省略された欄は0として扱う(): void
    {
        // Gemini は値が 0 の欄を JSON から省く。他社の「取れなかった = null」とは意味が違う
        $this->fake($this->candidate(
            [['text' => '{"ok": true}']],
            usage: ['promptTokenCount' => 10, 'candidatesTokenCount' => 3, 'totalTokenCount' => 13],
        ));

        $tokens = $this->driver()->run($this->request())->tokens;

        $this->assertSame(10, $tokens->input);
        $this->assertSame(0, $tokens->cached);
        $this->assertNull($tokens->cacheWrite);
        $this->assertSame(3, $tokens->output);
        $this->assertSame(0, $tokens->reasoning);
    }

    public function test_usage_metadataごと無ければトークンはnullにする(): void
    {
        $this->fake($this->candidate([['text' => '{"ok": true}']], usage: null));

        $this->assertNull($this->driver()->run($this->request())->tokens);
    }

    public function test_prompt_token_countが無いusageはトークン全体をnullにする(): void
    {
        // 入力が 0 トークンということは無いので、この欄の欠損は「数えられていない」。
        // 0 で埋めると AiPricing が「推定費用 $0」という無かった数字を作ってしまう
        $this->fake($this->candidate(
            [['text' => '{"ok": true}']],
            usage: ['totalTokenCount' => 13, 'promptTokensDetails' => [['modality' => 'TEXT']]],
        ));

        $this->assertNull($this->driver()->run($this->request())->tokens);
    }

    public function test_リクエストには正規スキーマとapiキーヘッダが入る(): void
    {
        $this->fake($this->candidate([['text' => '{"ok": true}']]));

        $this->driver()->run($this->request(['thinking_level' => 'low'], 'gemini-3.8-flash', 90));

        // 送信した中身を取り出してからテスト本体で検証する。
        // assertSent の closure の中で assert すると、失敗しても closure が false を返しただけの
        // 扱いになり「送信された」判定に吸収されて素通りする（実 API の 400 で気づいた）
        $sent = null;
        Http::assertSent(function (Request $request) use (&$sent) {
            $sent = $request;

            return true;
        });

        $body = $sent->data();
        $config = $body['generationConfig'];

        $this->assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent', $sent->url());
        $this->assertTrue($sent->hasHeader('x-goog-api-key', 'AIzaSyTestKey123'));
        $this->assertSame([['role' => 'user', 'parts' => [['text' => '判定してください']]]], $body['contents']);

        // 構造化出力の指定はこの2つ。responseFormat.text.mimeType は文字列を受け付けず 400 になる
        $this->assertSame('application/json', $config['responseMimeType']);
        $this->assertArrayNotHasKey('responseFormat', $config);
        // 正規スキーマ（additionalProperties・required・type 配列）をそのまま渡す
        $this->assertSame(self::SCHEMA, $config['responseJsonSchema']);

        // 出力上限を送らないと長い生成が timeout で切れ、usageMetadata を取れないまま課金だけが残る
        $this->assertSame(config('ai.providers.gemini.max_output_tokens'), $config['maxOutputTokens']);
        $this->assertSame(['thinkingLevel' => 'low'], $config['thinkingConfig']);
    }

    public function test_出力上限が設定されていなければ送信せずに例外にする(): void
    {
        Http::fake();
        config(['ai.providers.gemini.max_output_tokens' => 0]);

        $this->expectExceptionMessage('ai.providers.gemini.max_output_tokens');

        try {
            $this->driver()->run($this->request());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_思考の設定が未設定ならthinking_configごと送らない(): void
    {
        $this->fake($this->candidate([['text' => '{"ok": true}']]));

        $this->driver()->run($this->request(['thinking_level' => '', 'thinking_budget' => '']));

        Http::assertSent(fn (Request $request) => ! array_key_exists('thinkingConfig', $request->data()['generationConfig']));
    }

    /**
     * 0（思考オフ）と -1（動的）は意味のある値なので、空欄と同じ扱いで落とさない。
     */
    public function test_thinking_budgetは0と_1も整数として送る(): void
    {
        foreach (['0' => 0, '-1' => -1, '1024' => 1024] as $input => $expected) {
            $this->fake($this->candidate([['text' => '{"ok": true}']]));

            $this->driver()->run($this->request(['thinking_budget' => $input]));

            Http::assertSent(fn (Request $request) => $request->data()['generationConfig']['thinkingConfig'] === ['thinkingBudget' => $expected]);
        }
    }

    public function test_thinking_levelとthinking_budgetの同時指定は送信せずに例外にする(): void
    {
        Http::fake();

        try {
            // thinkingLevel は Gemini 3 系・thinkingBudget は 2.5 系。片方を黙って選ばない
            $this->driver()->run($this->request(['thinking_level' => 'high', 'thinking_budget' => '1024']));
            $this->fail('例外になるはず');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('同時に指定できません', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_timeoutはリクエストの値がそのまま渡る(): void
    {
        $seen = null;
        Http::fake(['generativelanguage.googleapis.com/*' => function (Request $request, array $options) use (&$seen) {
            $seen = $options['timeout'];

            return Http::response($this->candidate([['text' => '{"ok": true}']]));
        }]);

        $this->driver()->run($this->request([], 'gemini-3.8-flash', 45));

        $this->assertSame(45, $seen);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function 完了しなかった理由(): array
    {
        return ['出力上限' => ['MAX_TOKENS'], '安全性' => ['SAFETY']];
    }

    #[DataProvider('完了しなかった理由')]
    public function test_finish_reasonが_sto_p以外ならusage付きの例外にする(string $finishReason): void
    {
        $this->fake($this->candidate([['text' => '{"ok":']], $finishReason));

        try {
            $this->driver()->run($this->request());
            $this->fail('AiRequestFailed になるはず');
        } catch (AiRequestFailed $e) {
            $this->assertStringContainsString($finishReason, $e->getMessage());
            $this->assertSame(AiProvider::Gemini, $e->provider);
            $this->assertSame('gemini-3.8-flash', $e->model);
            // 打ち切り・ブロックでも課金されているため、トークン数は記録に渡す
            $this->assertSame(120, $e->usage->input);
            $this->assertSame(55, $e->usage->output);
        }
    }

    public function test_入力がブロックされたらusage付きの例外にする(): void
    {
        // 入力が弾かれた場合は candidates が空で promptFeedback だけ返る
        $this->fake([
            'promptFeedback' => ['blockReason' => 'PROHIBITED_CONTENT', 'safetyRatings' => []],
            'usageMetadata' => ['promptTokenCount' => 120, 'totalTokenCount' => 120],
        ]);

        try {
            $this->driver()->run($this->request());
            $this->fail('AiRequestFailed になるはず');
        } catch (AiRequestFailed $e) {
            $this->assertStringContainsString('ブロック', $e->getMessage());
            $this->assertStringContainsString('PROHIBITED_CONTENT', $e->getMessage());
            $this->assertSame(120, $e->usage->input);
        }
    }

    public function test_形の違う応答でもトークンを残した例外にする(): void
    {
        // 中継サーバや将来の版が別の形を返しても、警告由来の ErrorException にせず
        // AiRequestFailed にする（課金済みのトークンが ai_usages に残らなくなるため）
        $this->fake([
            'candidates' => [['content' => ['parts' => '{"ok": true}'], 'finishReason' => 'STOP']],
            'usageMetadata' => self::USAGE,
        ]);

        try {
            $this->driver()->run($this->request());
            $this->fail('AiRequestFailed になるはず');
        } catch (AiRequestFailed $e) {
            $this->assertStringContainsString('テキスト出力', $e->getMessage());
            $this->assertSame(120, $e->usage->input);
        }
    }

    public function test_429はレート上限と分かる文言にしキーの断片を伏せる(): void
    {
        $this->fake(['error' => [
            'code' => 429,
            'message' => 'Quota exceeded for quota metric with key AIzaSyTestKey123.',
            'status' => 'RESOURCE_EXHAUSTED',
        ]], 429);

        try {
            $this->driver()->run($this->request());
            $this->fail('例外になるはず');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(AiRequestFailed::class, $e, '429 は課金されないので usage 付きの失敗にしない');
            $this->assertStringContainsString('HTTP 429 RESOURCE_EXHAUSTED', $e->getMessage());
            $this->assertStringContainsString('レート上限', $e->getMessage());
            $this->assertStringContainsString('Quota exceeded', $e->getMessage());
            $this->assertStringNotContainsString('AIzaSyTestKey123', $e->getMessage());
            $this->assertStringContainsString('AIza***', $e->getMessage());
        }
    }

    public function test_400はerror_messageだけを載せレート上限の但し書きを付けない(): void
    {
        $this->fake(['error' => [
            'code' => 400,
            'message' => 'API key not valid. Please pass a valid API key.',
            'status' => 'INVALID_ARGUMENT',
            'details' => [['reason' => 'API_KEY_INVALID']],
        ]], 400);

        try {
            $this->driver()->run($this->request());
            $this->fail('例外になるはず');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTP 400 INVALID_ARGUMENT', $e->getMessage());
            $this->assertStringContainsString('API key not valid', $e->getMessage());
            $this->assertStringNotContainsString('レート上限', $e->getMessage());
            $this->assertStringNotContainsString('API_KEY_INVALID', $e->getMessage(), 'error.message 以外の本文は載せない');
        }
    }

    public function test_2xxでも本文がjsonでなければ診断できる例外にする(): void
    {
        // base_url をプロキシ等に向けると起こりうる。型エラー（TypeError）を analysis_steps.error に残さない
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response('<html><body>502 Bad Gateway</body></html>', 200, ['Content-Type' => 'text/html'])]);

        try {
            $this->driver()->run($this->request());
            $this->fail('例外になるはず');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('応答が JSON ではありません', $e->getMessage());
            $this->assertStringContainsString('base_url', $e->getMessage(), '復旧の手掛かり（接続先）を出す');
            $this->assertStringContainsString('502 Bad Gateway', $e->getMessage());
        }
    }

    public function test_接続失敗は例外にする(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Gemini API に接続できませんでした: cURL error 28');

        $this->driver()->run($this->request());
    }

    public function test_キー未登録なら送信せずに例外にする(): void
    {
        $this->setApiKey(AiProvider::Gemini, null);
        Http::fake();

        try {
            $this->driver()->run($this->request());
            $this->fail('例外になるはず');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('.env の GEMINI_API_KEY が未設定', $e->getMessage());
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
