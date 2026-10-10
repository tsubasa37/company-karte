<?php

namespace Tests\Feature;

use App\Actions\BuildAiStatusAction;
use App\Enums\AiProvider;
use App\Models\AiProviderConfig;
use App\Models\AiSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WithApiKeys;
use Tests\Support\WithoutDriver;
use Tests\TestCase;

/**
 * Tier ごとの実行先を画面から切り替える経路。
 * 「キー未設定の実行先を選べない（実行時に止まるだけの状態を作らない）」ことが要点。
 */
class AiRoutingTest extends TestCase
{
    use RefreshDatabase;
    use WithApiKeys;
    use WithoutDriver;

    /** キーは設定ファイル側、モデルは DB 側 */
    private function registerOpenAi(): void
    {
        $this->setApiKey(AiProvider::OpenAi, 'sk-test');
        AiProviderConfig::create(['provider' => AiProvider::OpenAi, 'model_analysis' => 'gpt-5']);
    }

    public function test_キー設定済みのプロバイダを実行先に保存できる(): void
    {
        $this->registerOpenAi();

        $this->put(route('ai-routing.update'), [
            'analysis_provider' => 'openai',
            'light_provider' => 'codex',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $setting = AiSetting::sole();
        $this->assertSame(AiProvider::OpenAi, $setting->analysis_provider);
        $this->assertSame(AiProvider::Codex, $setting->light_provider);
    }

    public function test_キー未設定のプロバイダは選べない(): void
    {
        // Gemini はキー未設定。選ばせても実行時に例外で止まるだけなので入口で弾く
        // （設定画面は Inertia の web 経路なので、失敗はセッションのエラーで返る）
        $this->put(route('ai-routing.update'), [
            'analysis_provider' => 'gemini',
            'light_provider' => 'codex',
        ])->assertSessionHasErrors('analysis_provider');

        $this->put(route('ai-routing.update'), [
            'analysis_provider' => 'codex',
            'light_provider' => 'gemini',
        ])->assertSessionHasErrors('light_provider');

        $this->assertStringContainsString('キーを設定済み', session('errors')->first('light_provider'));
        $this->assertSame(AiProvider::Codex, AiSetting::sole()->light_provider);
    }

    public function test_ドライバ未実装のプロバイダはキーを登録しても選べない(): void
    {
        // キーの登録自体は許すが、選ばせると実行時に「ドライバは未登録です」で必ず落ちる。
        // 実在の4プロバイダには全てドライバがあるので、未実装の状態は DriverRegistry を差し替えて作る
        $this->withoutDriverFor(AiProvider::Gemini);
        $this->setApiKey(AiProvider::Gemini, 'AIza-test');
        AiProviderConfig::create(['provider' => AiProvider::Gemini, 'model_analysis' => 'gemini-3.8-flash']);

        $this->put(route('ai-routing.update'), [
            'analysis_provider' => 'gemini',
            'light_provider' => 'codex',
        ])->assertSessionHasErrors('analysis_provider');

        $this->assertSame(AiProvider::Codex, AiSetting::sole()->analysis_provider);
        $this->assertSame(['codex'], app(BuildAiStatusAction::class)->execute()['available']);
    }

    public function test_プロバイダに無い値や欠けた項目は弾かれる(): void
    {
        $this->put(route('ai-routing.update'), ['analysis_provider' => 'foo', 'light_provider' => 'codex'])
            ->assertSessionHasErrors('analysis_provider');

        $this->put(route('ai-routing.update'), ['analysis_provider' => 'codex'])
            ->assertSessionHasErrors('light_provider');
    }

    public function test_保存した実行先と候補が画面用の状態に反映される(): void
    {
        $this->registerOpenAi();
        $this->put(route('ai-routing.update'), ['analysis_provider' => 'openai', 'light_provider' => 'codex']);

        $status = app(BuildAiStatusAction::class)->execute();

        $this->assertSame(['analysis' => 'openai', 'light' => 'codex'], $status['routing']);
        $this->assertSame(['codex', 'openai'], $status['available']);
        $this->assertSame('OpenAI API', $status['current']['analysis']['providerLabel']);
        $this->assertSame('gpt-5', $status['current']['analysis']['model']);
        $this->assertSame('codex', $status['current']['light']['provider']);
    }

    public function test_モデル未設定のプロバイダは実行先に選べない(): void
    {
        // キーとドライバが揃っていてもモデルが空だと、分析を押した時点で落ちる。
        // 押すまで気づけないので、選ぶ時点で止める
        $this->setApiKey(AiProvider::Gemini, 'AIza-test');
        AiProviderConfig::create(['provider' => AiProvider::Gemini, 'model_light' => 'gemini-3.8-flash']);

        $this->put(route('ai-routing.update'), [
            'analysis_provider' => 'gemini',
            'light_provider' => 'gemini',
        ])->assertSessionHasErrors('analysis_provider');

        $this->assertStringContainsString('分析・要約用モデルが未設定', session('errors')->first('analysis_provider'));
        // 抽出・判定は設定済みなのでこちらは通る
        $this->assertFalse(session('errors')->has('light_provider'));
        $this->assertSame(AiProvider::Codex, AiSetting::sole()->analysis_provider);
    }

    public function test_保存後もエンジン設定ページに留まる(): void
    {
        // Referrer-Policy: no-referrer で Referer が飛ばず、Inertia の遷移はセッションの前URLも
        // 更新しないため、back() だとダッシュボードへ飛んでいた。戻り先を明示していることを固定する
        $this->put(route('ai-routing.update'), ['analysis_provider' => 'codex', 'light_provider' => 'codex'])
            ->assertRedirect(route('settings.ai'));

        $this->post(route('ai-provider.test', ['provider' => 'codex']))
            ->assertRedirect(route('settings.ai'));

        $this->put(route('ai-provider.update', ['provider' => 'codex']), ['model_light' => 'gpt-5.4-codex'])
            ->assertRedirect(route('settings.ai'));
    }

    public function test_codexはモデル空欄でも実行先に選べる(): void
    {
        // codex は空欄なら CLI の既定モデルで動くので、モデル必須の対象にしない
        AiProviderConfig::where('provider', 'codex')->sole()->update(['model_analysis' => null, 'model_light' => null]);

        $this->put(route('ai-routing.update'), ['analysis_provider' => 'codex', 'light_provider' => 'codex'])
            ->assertRedirect()->assertSessionHasNoErrors();
    }
}
