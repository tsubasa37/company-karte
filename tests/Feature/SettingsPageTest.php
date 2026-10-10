<?php

namespace Tests\Feature;

use App\Enums\AiKind;
use App\Enums\AiProvider;
use App\Models\AiProviderConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WithApiKeys;
use Tests\TestCase;

/**
 * 設定画面（1節=1ページ）の検証。
 *
 * 見るのは2つ。(1) 各ページがその節の描画に要る props を渡すこと、
 * (2) 節をまたいだ props を持ち込まないこと。1ページに全節を載せていた頃は
 * プロファイルを直しに来ただけでも AI 使用状況の集計が走っていたので、そこへ戻さない。
 */
class SettingsPageTest extends TestCase
{
    use RefreshDatabase;
    use WithApiKeys;

    /** 節のルート名 => そのページが持つ props のキー */
    private const SECTIONS = [
        'settings.profile' => 'profile',
        'settings.ai' => 'ai',
        'settings.usage' => 'aiUsage',
        'settings.mail' => 'gmail',
        'settings.backup' => 'backup',
    ];

    /** キーは設定ファイル側、モデルは DB 側。両方そろった状態を作る */
    private function registerOpenAi(): void
    {
        $this->setApiKey(AiProvider::OpenAi, 'sk-secret-value');
        AiProviderConfig::create([
            'provider' => AiProvider::OpenAi,
            'model_analysis' => 'gpt-5',
            'model_light' => 'gpt-5-mini',
        ]);
    }

    public function test_設定のトップは既定の節へ送る(): void
    {
        $this->get('/settings')->assertRedirect(route('settings.profile'));
    }

    public function test_各ページが自分の節の描画に要る_propsを渡す(): void
    {
        $this->get(route('settings.profile'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Settings/Profile')->has('profile'));

        $this->get(route('settings.ai'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Ai')
                // 実行先（Tier ごと）とプロバイダごとの設定
                ->has('ai.routing.analysis')
                ->has('ai.routing.light')
                ->has('ai.current.analysis.provider')
                ->has('ai.available')
                // Tier の実行先に出すのは生成用だけ（判定専用は ai.decision の別枠。Jev を足したので cases() から変えた）
                ->has('ai.providers', count(AiProvider::ofKind(AiKind::Generation)))
                // 判定エンジンは既定でオフ。キーが無いので候補も空
                ->where('ai.decision.provider', null)
                // 口コミの材料選抜も既定でオフ（従来どおり新しい順）
                ->where('ai.decision.reviewOrdering', false)
                ->where('ai.decision.available', [])
                ->has('ai.decision.providers', 1)
                ->where('ai.decision.providers.0.provider', 'jev')
                // 判定エンジン節の描画に要る表示名・モデル候補（単価表）・注記。キー有無に依存しない項目だけを見る
                ->where('ai.decision.providers.0.label', 'Jev（TypeSafe AI）')
                ->has('ai.decision.providers.0.models', 1)
                ->where('ai.decision.providers.0.models.0.name', 'jev-1.13.0')
                ->has('ai.decision.providers.0.notes', 2)
                // 注記は利用者向け。開発内部の単位コード（U0〜U9）を画面に出さない
                ->where('ai.decision.providers.0.notes', fn ($notes) => collect($notes)->every(fn ($n) => preg_match('/\bU\d\b/', $n) === 0)));

        $this->get(route('settings.usage'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Usage')
                // 実行先ごとに1行
                ->has('aiUsage.providers', count(AiProvider::cases()))
                ->has('aiUsage.days'));

        $this->get(route('settings.mail'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Settings/Mail')->has('gmail'));

        $this->get(route('settings.backup'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Settings/Backup')->has('backup'));
    }

    public function test_各ページは他の節の_propsを持ち込まない(): void
    {
        foreach (self::SECTIONS as $route => $own) {
            $props = $this->get(route($route))->assertOk()->viewData('page')['props'];

            $this->assertArrayHasKey($own, $props, "{$route} に {$own} が無い");

            foreach (self::SECTIONS as $other) {
                if ($other !== $own) {
                    $this->assertArrayNotHasKey($other, $props, "{$route} に {$other} が混ざっている");
                }
            }
        }
    }

    public function test_接続テストの結果は押していなければ_nullで届く(): void
    {
        // 画面は flash.aiTest / flash.gmailTest を読む。鍵ごと無いと undefined 参照になる
        $this->get(route('settings.ai'))
            ->assertInertia(fn ($page) => $page->where('flash.aiTest', null));

        $this->get(route('settings.mail'))
            ->assertInertia(fn ($page) => $page->where('flash.gmailTest', null));
    }

    public function test_ap_iキーは画面へ返らず設定済みかと変数名だけが届く(): void
    {
        $this->registerOpenAi();

        $response = $this->get(route('settings.ai'))->assertOk();

        $ai = $response->viewData('page')['props']['ai'];
        $providers = collect($ai['providers'])->keyBy('provider');

        $this->assertTrue($providers['openai']['hasApiKey']);
        $this->assertFalse($providers['codex']['hasApiKey']);
        // モデル名は秘密ではないので画面に出す。変数名も「どこに書くか」の案内として出す
        $this->assertSame('gpt-5', $providers['openai']['modelAnalysis']);
        $this->assertSame('OPENAI_API_KEY', $providers['openai']['apiKeyEnvName']);
        $this->assertNull($providers['codex']['apiKeyEnvName'], 'codex はキーを持たない');

        foreach ($ai['providers'] as $provider) {
            $this->assertArrayNotHasKey('api_key', $provider);
            $this->assertArrayNotHasKey('apiKey', $provider);
        }

        // 入れ子のどこにも値が出ていないこと（項目名を変えても素通りしないよう全文で見る）
        $this->assertStringNotContainsString('sk-secret-value', json_encode($ai, JSON_THROW_ON_ERROR));
    }

    public function test_ai設定の部分更新で要求した_propsが返る(): void
    {
        // AI の各フォームは only（Partial Reload）で保存する。指定した props と
        // 常時返る errors が届くこと。
        // 資産バージョンが合わないと Inertia は 409（全体再取得）を返すので、初回描画の値を使う
        $version = $this->get(route('settings.ai'))->viewData('page')['version'];

        $response = $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Component' => 'Settings/Ai',
            'X-Inertia-Partial-Data' => 'ai,flash',
        ])->get(route('settings.ai'))->assertOk();

        $props = $response->json('props');

        $this->assertSame('Settings/Ai', $response->json('component'));
        $this->assertArrayHasKey('providers', $props['ai']);
        // flash（接続テストの結果）と errors は only に含めた分・常時返る分として届く
        $this->assertArrayHasKey('flash', $props);
        $this->assertArrayHasKey('errors', $props);
    }

    public function test_キー設定済みのプロバイダだけが実行先の候補に出る(): void
    {
        $this->assertSame(['codex'], $this->availableProviders());

        $this->registerOpenAi();

        $this->assertSame(['codex', 'openai'], $this->availableProviders());
    }

    /**
     * @return array<int, string>
     */
    private function availableProviders(): array
    {
        return $this->get(route('settings.ai'))->viewData('page')['props']['ai']['available'];
    }
}
