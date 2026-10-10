<?php

namespace Tests\Unit;

use App\Enums\AiProvider;
use App\Enums\AiTier;
use App\Models\AiProviderConfig;
use App\Models\AiSetting;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Decision\DecisionDriverRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\WithApiKeys;
use Tests\Support\WithoutDriver;
use Tests\TestCase;

/**
 * 実行先の解決。「キー未設定・モデル未設定で黙って別の実行先に落ちない」ことが要点。
 * API キーは設定ファイル（config）が唯一の置き場所なので、テストは config を差し替えて作る。
 */
class AiSettingsTest extends TestCase
{
    use RefreshDatabase;
    use WithApiKeys;
    use WithoutDriver;

    private function settings(): AiSettings
    {
        return $this->app->make(AiSettings::class);
    }

    private function selectProvider(AiTier $tier, AiProvider $provider): void
    {
        AiSetting::sole()->update([
            $tier === AiTier::Analysis ? 'analysis_provider' : 'light_provider' => $provider,
        ]);
    }

    public function test_両tierともcodexでモデル未設定なら既定モデルで解決される(): void
    {
        config(['services.codex.timeout' => 180]);
        // migration は開発機の .env（CODEX_MODEL）を codex 行にコピーするため、未設定の状態を明示して作る
        AiProviderConfig::where('provider', 'codex')->sole()->update(['model_analysis' => null, 'model_light' => null]);

        $target = $this->settings()->resolve(AiTier::Analysis);

        $this->assertSame(AiProvider::Codex, $target->provider);
        $this->assertNull($target->model, 'codex はモデル未設定でも既定モデルで動く');
        $this->assertSame([], $target->options);
        $this->assertSame(180, $target->timeout);
        $this->assertSame(AiProvider::Codex, $this->settings()->resolve(AiTier::Light)->provider);
    }

    public function test_codexのモデルはtierごとにcodex行から解決される(): void
    {
        AiProviderConfig::where('provider', 'codex')->sole()->update([
            'model_analysis' => 'gpt-analysis',
            'model_light' => 'gpt-light',
            'options' => ['analysis' => ['reasoning_effort' => 'high'], 'light' => []],
        ]);

        $this->assertSame('gpt-analysis', $this->settings()->resolve(AiTier::Analysis)->model);
        $this->assertSame(['reasoning_effort' => 'high'], $this->settings()->resolve(AiTier::Analysis)->options);
        $this->assertSame('gpt-light', $this->settings()->resolve(AiTier::Light)->model);
        $this->assertSame([], $this->settings()->resolve(AiTier::Light)->options);
    }

    public function test_api系でキー未設定なら変数名つきの日本語の例外で止める(): void
    {
        $this->selectProvider(AiTier::Analysis, AiProvider::OpenAi);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('.env の OPENAI_API_KEY が未設定です');

        $this->settings()->resolve(AiTier::Analysis);
    }

    public function test_api系でキーが空文字でも未設定として扱う(): void
    {
        $this->setApiKey(AiProvider::Gemini, '');
        AiProviderConfig::create(['provider' => AiProvider::Gemini, 'model_light' => 'gemini-flash']);
        $this->selectProvider(AiTier::Light, AiProvider::Gemini);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('.env の GEMINI_API_KEY が未設定です');

        $this->settings()->resolve(AiTier::Light);
    }

    public function test_api系でそのtierのモデルが空なら日本語の例外で止める(): void
    {
        $this->setApiKey(AiProvider::OpenAi, 'sk-test');
        AiProviderConfig::create(['provider' => AiProvider::OpenAi, 'model_analysis' => 'gpt-5']);
        $this->selectProvider(AiTier::Light, AiProvider::OpenAi);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API の抽出・判定用モデルが未設定です。設定画面から登録してください');

        $this->settings()->resolve(AiTier::Light);
    }

    public function test_api系はキーとモデルが揃えばconfigのtimeoutで解決される(): void
    {
        config(['ai.providers.openai.timeout' => 90]);
        $this->setApiKey(AiProvider::OpenAi, 'sk-test');
        AiProviderConfig::create([
            'provider' => AiProvider::OpenAi,
            'model_analysis' => 'gpt-5',
            'options' => ['analysis' => ['reasoning_effort' => 'low']],
        ]);
        $this->selectProvider(AiTier::Analysis, AiProvider::OpenAi);

        $target = $this->settings()->resolve(AiTier::Analysis);

        $this->assertSame(AiProvider::OpenAi, $target->provider);
        $this->assertSame('gpt-5', $target->model);
        $this->assertSame(['reasoning_effort' => 'low'], $target->options);
        $this->assertSame(90, $target->timeout);
    }

    public function test_使えるプロバイダはcodexとキー設定済みのapi系(): void
    {
        $this->assertSame([AiProvider::Codex], $this->settings()->available());

        $this->setApiKey(AiProvider::OpenAi, 'sk-test');

        $this->assertSame([AiProvider::Codex, AiProvider::OpenAi], $this->settings()->available());
    }

    public function test_キーが空文字や空白だけなら候補に入れない(): void
    {
        $this->setApiKey(AiProvider::OpenAi, '');
        $this->assertSame([AiProvider::Codex], $this->settings()->available());

        $this->setApiKey(AiProvider::OpenAi, '   ');
        $this->assertSame([AiProvider::Codex], $this->settings()->available());
    }

    public function test_ドライバ未実装のプロバイダはキーを設定しても候補に入れない(): void
    {
        // 選ばせると実行時に「ドライバは未登録です」で必ず落ちる。設定できるのに動かない状態を作らない。
        // 実在の4プロバイダには全てドライバがあるので、未実装の状態は DriverRegistry を差し替えて作る
        $this->withoutDriverFor(AiProvider::Gemini);
        $this->setApiKey(AiProvider::Gemini, 'AIza-test');
        $this->setApiKey(AiProvider::Anthropic, 'sk-ant-test');

        $this->assertSame([AiProvider::Codex, AiProvider::Anthropic], $this->settings()->available());
    }

    public function test_ドライバ実装済みでキー設定済みのプロバイダは候補に入る(): void
    {
        $this->setApiKey(AiProvider::Gemini, 'AIza-test');
        $this->setApiKey(AiProvider::Anthropic, 'sk-ant-test');

        $this->assertSame(
            [AiProvider::Codex, AiProvider::Gemini, AiProvider::Anthropic],
            $this->settings()->available(),
            'キー未設定の OpenAI だけが外れる',
        );
    }

    public function test_timeoutはtierに選ばれている実行先のものを返しキーの有無は見ない(): void
    {
        config(['services.codex.timeout' => 45, 'ai.providers.gemini.timeout' => 70]);
        // キー未登録でも締め切りの見積もりは出せる（実行可否はここで判定しない）
        $this->selectProvider(AiTier::Light, AiProvider::Gemini);

        $this->assertSame(45, $this->settings()->timeoutFor(AiTier::Analysis));
        $this->assertSame(70, $this->settings()->timeoutFor(AiTier::Light));
    }

    public function test_接続テスト用は抽出判定用モデルを優先し空なら分析用を使う(): void
    {
        $this->setApiKey(AiProvider::OpenAi, 'sk-test');
        AiProviderConfig::create(['provider' => AiProvider::OpenAi, 'model_analysis' => 'gpt-5', 'model_light' => 'gpt-5-mini']);
        $this->assertSame('gpt-5-mini', $this->settings()->probeTarget(AiProvider::OpenAi)->model);

        AiProviderConfig::where('provider', 'openai')->sole()->update(['model_light' => null]);
        $this->assertSame('gpt-5', $this->settings()->probeTarget(AiProvider::OpenAi)->model);
    }

    public function test_接続テスト用でもモデルが両方空なら例外にする(): void
    {
        $this->setApiKey(AiProvider::OpenAi, 'sk-test');
        AiProviderConfig::create(['provider' => AiProvider::OpenAi]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API のモデルが未設定です');

        $this->settings()->probeTarget(AiProvider::OpenAi);
    }

    public function test_判定専用の実行先はキーを設定してもtierの候補に入れない(): void
    {
        $this->setApiKey(AiProvider::Jev, 'ts-test');

        $this->assertSame([AiProvider::Codex], $this->settings()->available());
    }

    public function test_判定エンジンの候補はキー設定済みの判定専用だけ(): void
    {
        $this->setApiKey(AiProvider::OpenAi, 'sk-test');
        $this->assertSame([], $this->settings()->availableDecision(), 'キーが無ければ候補に出さない。生成用は判定の候補にしない');

        $this->setApiKey(AiProvider::Jev, 'ts-test');
        $this->assertSame([AiProvider::Jev], $this->settings()->availableDecision());
    }

    public function test_判定ドライバ未実装の実行先はキーを設定しても判定の候補に入れない(): void
    {
        // 生成側の WithoutDriver と同じ不変条件。実在の判定専用には全てドライバがあるので差し替えて作る
        $registry = Mockery::mock(DecisionDriverRegistry::class);
        $registry->shouldReceive('supports')->andReturn(false);
        $this->app->instance(DecisionDriverRegistry::class, $registry);
        $this->setApiKey(AiProvider::Jev, 'ts-test');

        $this->assertSame([], $this->settings()->availableDecision());
    }

    public function test_判定エンジンは既定で未設定(): void
    {
        $this->assertNull($this->settings()->decisionProvider());
        $this->assertFalse($this->settings()->isDecisionConfigured());

        AiSetting::sole()->update(['decision_provider' => AiProvider::Jev]);

        $this->assertSame(AiProvider::Jev, $this->settings()->decisionProvider());
        $this->assertTrue($this->settings()->isDecisionConfigured());
    }

    public function test_面接メモを送るのは判定エンジンが設定済みで同意スイッチがオンのときだけ(): void
    {
        $this->assertFalse($this->settings()->isDecisionInterviewNotesAllowed(), '既定は送らない');

        AiSetting::sole()->update(['decision_provider' => null, 'decision_send_interview_notes' => true]);
        $this->assertFalse($this->settings()->isDecisionInterviewNotesAllowed(), '判定エンジンがオフなら送らない');

        AiSetting::sole()->update(['decision_provider' => AiProvider::Jev, 'decision_send_interview_notes' => false]);
        $this->assertFalse($this->settings()->isDecisionInterviewNotesAllowed(), '同意スイッチがオフなら送らない');

        AiSetting::sole()->update(['decision_provider' => AiProvider::Jev, 'decision_send_interview_notes' => true]);
        $this->assertTrue($this->settings()->isDecisionInterviewNotesAllowed());
    }

    public function test_判定エンジンが未設定なら日本語の例外で止める(): void
    {
        $this->expectException(\RuntimeException::class);
        // 判定エンジンを選ぶ画面ができたので、案内先を保存口（PUT）から設定画面に戻した
        $this->expectExceptionMessage('判定エンジンが未設定です。設定画面の「AIエンジン」→「判定エンジン」で実行先を選んで保存してください');

        $this->settings()->resolveDecision();
    }

    public function test_判定エンジンのキーが無ければ変数名つきの例外で止める(): void
    {
        AiSetting::sole()->update(['decision_provider' => AiProvider::Jev]);
        AiProviderConfig::create(['provider' => AiProvider::Jev, 'model_decision' => 'jev-1.13.0']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('.env の TYPESAFE_API_KEY が未設定です');

        $this->settings()->resolveDecision();
    }

    public function test_判定用モデルが無ければ日本語の例外で止める(): void
    {
        $this->setApiKey(AiProvider::Jev, 'ts-test');
        AiSetting::sole()->update(['decision_provider' => AiProvider::Jev]);
        $this->assertFalse($this->settings()->hasDecisionModel(AiProvider::Jev), '一度も保存していない状態');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Jev（TypeSafe AI） の判定用モデルが未設定です。設定画面から登録してください');

        $this->settings()->resolveDecision();
    }

    public function test_判定エンジンはキーとモデルが揃えばconfigのtimeoutで解決される(): void
    {
        // 開発機の .env（TYPESAFE_TIMEOUT）で結果が変わらないよう既定値を明示する
        config(['ai.providers.jev.timeout' => 10]);
        $this->setApiKey(AiProvider::Jev, 'ts-test');
        AiSetting::sole()->update(['decision_provider' => AiProvider::Jev]);
        AiProviderConfig::create(['provider' => AiProvider::Jev, 'model_decision' => 'jev-1.13.0']);

        $target = $this->settings()->resolveDecision();

        $this->assertTrue($this->settings()->hasDecisionModel(AiProvider::Jev));
        $this->assertSame(AiProvider::Jev, $target->provider);
        $this->assertSame('jev-1.13.0', $target->model);
        $this->assertSame([], $target->options);
        $this->assertSame(10, $target->timeout);
    }
}
