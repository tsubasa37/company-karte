<?php

namespace Tests\Feature;

use App\Actions\BuildAiStatusAction;
use App\Enums\AiProvider;
use App\Models\AiProviderConfig;
use App\Services\Ai\Driver\AiRequest;
use App\Services\Ai\Driver\AnthropicDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WithApiKeys;
use Tests\Support\WithoutDriver;
use Tests\TestCase;

/**
 * プロバイダごとの設定（モデル・固有オプション）を画面から入力する経路。
 * API キーはこの経路では扱わない（置き場所は設定ファイル1本）。
 * 「キーを送っても拒否されること」「キーの値が画面へ返らないこと」が要点。
 */
class AiProviderConfigTest extends TestCase
{
    use RefreshDatabase;
    use WithApiKeys;
    use WithoutDriver;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'model_analysis' => 'gpt-5',
            'model_light' => 'gpt-5-mini',
            'options' => [
                'analysis' => ['reasoning_effort' => 'low'],
                'light' => ['reasoning_effort' => ''],
            ],
        ];
    }

    public function test_モデルと固有オプションが保存され空のオプションは落ちる(): void
    {
        $this->put(route('ai-provider.update', 'openai'), $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();

        $config = AiProviderConfig::where('provider', 'openai')->sole();
        $this->assertSame('gpt-5', $config->model_analysis);
        $this->assertSame('gpt-5-mini', $config->model_light);
        // 未設定（空文字）は保存しない。「未設定なら鍵ごと送らない」を DB の形で成立させる
        $this->assertSame(['analysis' => ['reasoning_effort' => 'low']], $config->options);
    }

    public function test_キーを送ると拒否され変数名が示される(): void
    {
        $this->put(route('ai-provider.update', 'openai'), $this->payload(['api_key' => 'sk-should-not-be-here']))
            ->assertSessionHasErrors('api_key');
        $this->assertStringContainsString('.env の OPENAI_API_KEY', session('errors')->first('api_key'));

        // 拒否された送信で行が作られない（キーの経路を DB に作らない）
        $this->assertSame(0, AiProviderConfig::where('provider', 'openai')->count());
    }

    public function test_キーの列そのものが存在しない(): void
    {
        // 置き場所が2つある状態を作らないため、DB からは列ごと落としている
        $this->assertFalse(Schema::hasColumn('ai_provider_configs', 'api_key'));
    }

    public function test_codex行はキーを受け付けずモデルだけ保存できる(): void
    {
        $this->put(route('ai-provider.update', 'codex'), [
            'api_key' => 'sk-should-not-be-here',
            'model_analysis' => 'gpt-5',
        ])->assertSessionHasErrors('api_key');
        $this->assertStringContainsString('この画面では扱いません', session('errors')->first('api_key'));

        $this->put(route('ai-provider.update', 'codex'), [
            'model_analysis' => 'gpt-5.6',
            'model_light' => '',
            'options' => ['analysis' => [], 'light' => []],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $config = AiProviderConfig::where('provider', 'codex')->sole();
        $this->assertSame('gpt-5.6', $config->model_analysis);
        $this->assertNull($config->model_light);
        $this->assertNull($config->options);
    }

    public function test_画面に無い固有オプションや選択肢外の値は拒否する(): void
    {
        $this->put(route('ai-provider.update', 'openai'), $this->payload([
            'options' => ['analysis' => ['temperature' => 0.2]],
        ]))->assertSessionHasErrors('options.analysis');

        $this->put(route('ai-provider.update', 'openai'), $this->payload([
            'options' => ['analysis' => ['reasoning_effort' => 'ultra']],
        ]))->assertSessionHasErrors('options.analysis.reasoning_effort');

        // 固有オプションの無いプロバイダに鍵を送っても保存しない
        $this->put(route('ai-provider.update', 'codex'), [
            'options' => ['light' => ['reasoning_effort' => 'low']],
        ])->assertSessionHasErrors('options.light');

        $this->assertSame(0, AiProviderConfig::where('provider', 'openai')->count());
    }

    public function test_geminiは思考の深さと予算の同時指定を拒否する(): void
    {
        // thinkingLevel は 3 系・thinkingBudget は 2.5 系。保存できると実行するまで気づけない
        $this->put(route('ai-provider.update', 'gemini'), [
            'options' => ['analysis' => ['thinking_level' => 'high', 'thinking_budget' => 1024], 'light' => []],
        ])->assertSessionHasErrors('options.analysis.thinking_budget');

        $this->assertSame(0, AiProviderConfig::where('provider', 'gemini')->count());
    }

    public function test_geminiの思考の予算は範囲内の整数だけ受ける(): void
    {
        $reject = fn (mixed $value) => $this->put(route('ai-provider.update', 'gemini'), [
            'options' => ['analysis' => ['thinking_budget' => $value], 'light' => []],
        ])->assertSessionHasErrors('options.analysis.thinking_budget');

        $reject('high');
        $reject(-2);
        $reject(32769);

        // 0（思考オフ）と -1（動的）は意味のある値なので通す
        $this->put(route('ai-provider.update', 'gemini'), [
            'options' => ['analysis' => ['thinking_budget' => 0], 'light' => ['thinking_budget' => -1]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            ['analysis' => ['thinking_budget' => 0], 'light' => ['thinking_budget' => -1]],
            AiProviderConfig::where('provider', 'gemini')->sole()->options,
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function 噛み合わないanthropicの設定(): array
    {
        return [
            'モード未設定で effort' => [['effort' => 'high'], 'options.analysis.effort'],
            'モード未設定で予算' => [['budget_tokens' => 4096], 'options.analysis.budget_tokens'],
            'adaptive に予算' => [['thinking_mode' => 'adaptive', 'budget_tokens' => 4096], 'options.analysis.budget_tokens'],
            'enabled に effort' => [['thinking_mode' => 'enabled', 'effort' => 'high', 'budget_tokens' => 4096], 'options.analysis.effort'],
            'enabled で予算なし' => [['thinking_mode' => 'enabled'], 'options.analysis.budget_tokens'],
            '下限未満の予算' => [['thinking_mode' => 'enabled', 'budget_tokens' => 1023], 'options.analysis.budget_tokens'],
            'max_tokens 以上の予算' => [['thinking_mode' => 'enabled', 'budget_tokens' => 32000], 'options.analysis.budget_tokens'],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[DataProvider('噛み合わないanthropicの設定')]
    public function test_anthropicの噛み合わない推論設定は拒否する(array $options, string $key): void
    {
        config(['ai.providers.anthropic.max_tokens' => 32000]);

        $this->put(route('ai-provider.update', 'anthropic'), [
            'options' => ['analysis' => $options, 'light' => []],
        ])->assertSessionHasErrors($key);

        $this->assertSame(0, AiProviderConfig::where('provider', 'anthropic')->count());
    }

    public function test_anthropicの噛み合う推論設定は保存される(): void
    {
        $this->put(route('ai-provider.update', 'anthropic'), [
            'model_analysis' => 'claude-opus-5',
            'options' => [
                'analysis' => ['thinking_mode' => 'adaptive', 'effort' => 'high'],
                'light' => ['thinking_mode' => 'enabled', 'budget_tokens' => 4096],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([
            'analysis' => ['thinking_mode' => 'adaptive', 'effort' => 'high'],
            'light' => ['thinking_mode' => 'enabled', 'budget_tokens' => 4096],
        ], AiProviderConfig::where('provider', 'anthropic')->sole()->options);
    }

    public function test_apiキーは画面用の状態にも配列化にも出ない(): void
    {
        $this->setApiKey(AiProvider::OpenAi, 'sk-proj-secret-0123456789');
        $this->put(route('ai-provider.update', 'openai'), $this->payload());

        $config = AiProviderConfig::where('provider', 'openai')->sole();
        $this->assertArrayNotHasKey('api_key', $config->toArray());

        $status = app(BuildAiStatusAction::class)->execute();
        $openai = collect($status['providers'])->firstWhere('provider', 'openai');

        $this->assertTrue($openai['hasApiKey']);
        $this->assertTrue($openai['driverReady']);
        $this->assertSame('gpt-5', $openai['modelAnalysis']);
        $this->assertSame('reasoning_effort', $openai['fields'][0]['key']);
        $this->assertStringNotContainsString('sk-proj-secret', json_encode($status, JSON_UNESCAPED_UNICODE));
    }

    public function test_固有オプションは画面が引ける形で返る(): void
    {
        // 画面は options[tier][key] で引く。保存が無くても鍵は必ず揃い、JSON の配列にならないこと
        $status = app(BuildAiStatusAction::class)->execute();
        $openai = collect($status['providers'])->firstWhere('provider', 'openai');

        $this->assertEquals((object) ['reasoning_effort' => ''], $openai['options']['analysis']);
        $this->assertEquals((object) ['reasoning_effort' => ''], $openai['options']['light']);
        // 固有オプションの無いプロバイダも Tier の鍵は揃える（画面が undefined を踏まない）
        $codex = collect($status['providers'])->firstWhere('provider', 'codex');
        $this->assertSame('{"analysis":{},"light":{}}', json_encode($codex['options']));

        $this->put(route('ai-provider.update', 'openai'), $this->payload());
        $saved = collect(app(BuildAiStatusAction::class)->execute()['providers'])->firstWhere('provider', 'openai');

        $this->assertEquals((object) ['reasoning_effort' => 'low'], $saved['options']['analysis']);
        $this->assertEquals((object) ['reasoning_effort' => ''], $saved['options']['light']);
    }

    public function test_バリデーション失敗後のold_inputにキーは残らない(): void
    {
        // セッションは database ドライバ・暗号化なしのため、old input に残ると平文で DB に書かれる
        $this->put(route('ai-provider.update', 'openai'), $this->payload(['model_analysis' => str_repeat('x', 121)]))
            ->assertSessionHasErrors('model_analysis')
            ->assertSessionHasInput('model_light', 'gpt-5-mini')
            ->assertSessionMissing('_old_input.api_key');  // 送っていないので残らない
    }

    public function test_ドライバ未登録のプロバイダも表示されモデルを保存できる(): void
    {
        // 実在の4プロバイダには全てドライバがあるので、未実装の状態は DriverRegistry を差し替えて作る
        $this->withoutDriverFor(AiProvider::Gemini);
        $this->setApiKey(AiProvider::Gemini, 'AIza-test');

        $this->put(route('ai-provider.update', 'gemini'), [
            'model_light' => 'gemini-3.8-flash',
            'options' => ['analysis' => [], 'light' => []],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $status = app(BuildAiStatusAction::class)->execute();
        $gemini = collect($status['providers'])->firstWhere('provider', 'gemini');

        $this->assertTrue($gemini['hasApiKey']);
        $this->assertSame('GEMINI_API_KEY', $gemini['apiKeyEnvName']);
        // キーを設定しても実行先には選べない（画面はこの旨を出す）
        $this->assertFalse($gemini['driverReady']);
        $this->assertNotContains('gemini', $status['available']);
        $this->assertSame(4, count($status['providers']), 'codex / OpenAI / Gemini / Anthropic の4行');
    }

    public function test_ドライバ実装済みのプロバイダはキーを設定すると候補に入り固有オプションも出る(): void
    {
        $this->setApiKey(AiProvider::Gemini, 'AIza-test');

        $this->put(route('ai-provider.update', 'gemini'), [
            'model_light' => 'gemini-3.8-flash',
            'options' => ['analysis' => ['thinking_level' => 'high'], 'light' => []],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $status = app(BuildAiStatusAction::class)->execute();
        $gemini = collect($status['providers'])->firstWhere('provider', 'gemini');

        $this->assertTrue($gemini['driverReady']);
        $this->assertContains('gemini', $status['available']);
        $this->assertSame(
            ['thinking_level', 'thinking_budget'],
            array_column($gemini['fields'], 'key'),
            '推論オプションが画面に出る',
        );
        $this->assertSame('high', $gemini['options']['analysis']->thinking_level);
        $this->assertNotEmpty($gemini['notes'], 'データの送り先の注記（課金有効プロジェクト前提）を出す');
    }

    public function test_anthropicのmax_tokensが壊れていても設定画面は開ける(): void
    {
        // ANTHROPIC_MAX_TOKENS が空だと (int) env(...) が 0 になる。ここでフォーム項目の組み立てが
        // 落ちると BuildAiStatusAction が全プロバイダを回すので、codex しか使わない利用者の /settings まで 500 になる
        config(['ai.providers.anthropic.max_tokens' => 0]);

        $this->get(route('settings.ai'))->assertOk();

        $anthropic = collect(app(BuildAiStatusAction::class)->execute()['providers'])
            ->firstWhere('provider', 'anthropic');
        $budget = collect($anthropic['fields'])->firstWhere('key', 'budget_tokens');

        // 上限は出せないので外す。壊れた設定は実行時に AnthropicDriver が例外で止める
        $this->assertNull($budget['max']);
        $this->assertSame(1024, $budget['min']);
    }

    public function test_max_tokensが壊れていても保存は通り実行時に落ちる(): void
    {
        config(['ai.providers.anthropic.max_tokens' => 0]);
        // 実行時に落ちる理由が max_tokens だと分かるよう、キーは設定済みにしておく
        $this->setApiKey(AiProvider::Anthropic, 'sk-ant-test');

        // 上限が出せない以上ここでは弾けない（'max:' のような壊れた規則を組み立てない）
        $this->put(route('ai-provider.update', 'anthropic'), [
            'model_analysis' => 'claude-sonnet-5',
            'options' => ['analysis' => ['thinking_mode' => 'enabled', 'budget_tokens' => 4096], 'light' => []],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->expectExceptionMessage('ai.providers.anthropic.max_tokens');
        $this->app->make(AnthropicDriver::class)->run(new AiRequest(['type' => 'object'], 'x', 'claude-sonnet-5', [], 10));
    }

    public function test_数値の固有オプションは数値のまま画面に返る(): void
    {
        // 文字列にすると画面の v-model.number が書き戻した数値と型が食い違い、'0' と 0 が混ざる
        $this->put(route('ai-provider.update', 'gemini'), [
            'model_light' => 'gemini-3.8-flash',
            'options' => ['analysis' => ['thinking_budget' => 0], 'light' => ['thinking_budget' => -1]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $gemini = collect(app(BuildAiStatusAction::class)->execute()['providers'])->firstWhere('provider', 'gemini');

        $this->assertSame(0, $gemini['options']['analysis']->thinking_budget);
        $this->assertSame(-1, $gemini['options']['light']->thinking_budget);
        // select 項目は文字列のまま、未設定は空文字
        $this->assertSame('', $gemini['options']['analysis']->thinking_level);
    }

    public function test_存在しないプロバイダは404(): void
    {
        $this->put('/settings/ai/providers/foo', $this->payload())->assertNotFound();
    }

    public function test_判定専用の実行先はこの経路で保存できず404(): void
    {
        // 判定用モデルは専用の経路で扱う。生成用の経路で行を作らせない
        $this->put(route('ai-provider.update', 'jev'), [])->assertNotFound();

        $this->assertNull(AiProviderConfig::where('provider', 'jev')->first());
    }

    public function test_モデル選択の候補が単価と1社あたりの目安つきで届く(): void
    {
        $status = app(BuildAiStatusAction::class)->execute();
        $gemini = collect($status['providers'])->firstWhere('provider', 'gemini');

        $names = array_column($gemini['models'], 'name');
        $this->assertContains('gemini-3.8-flash', $names);
        // 安い順に並ぶ（選ぶ場で比べられるように）
        $costs = array_column($gemini['models'], 'perCompanyMicroUsd');
        $sorted = $costs;
        sort($sorted);
        $this->assertSame($sorted, $costs);

        $model = collect($gemini['models'])->firstWhere('name', 'gemini-3.8-flash');
        $this->assertSame(0.75, $model['inputUsd']);
        $this->assertSame(3.75, $model['outputUsd']);
        $this->assertFalse($model['hasBands']);
        $this->assertGreaterThan(0, $model['perCompanyMicroUsd'], '1社あたりの目安が出る');
        $this->assertGreaterThan(0, $model['perCompanyJpy'], '円換算は PHP 側で確定して渡す');

        // codex はサブスクで単価表を持たないので候補も出さない
        $codex = collect($status['providers'])->firstWhere('provider', 'codex');
        $this->assertSame([], $codex['models']);
    }
}
