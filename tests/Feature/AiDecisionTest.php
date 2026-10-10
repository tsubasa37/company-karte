<?php

namespace Tests\Feature;

use App\Enums\AiProvider;
use App\Enums\AiPurpose;
use App\Models\AiProviderConfig;
use App\Models\AiSetting;
use App\Models\AiUsage;
use App\Services\Ai\AiDecider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\WithApiKeys;
use Tests\TestCase;

/**
 * 判定エンジン（Jev）の保存と接続テストの経路。
 * 「キー未設定・モデル未設定の判定エンジンを選べない」「空で保存するとオフに戻りモデルは残る」
 * 「接続テストは未設定を通信せずに切り分け、失敗は理由をそのまま出す」が要点。
 */
class AiDecisionTest extends TestCase
{
    use RefreshDatabase;
    use WithApiKeys;

    /** 判定エンジンを設定済みにする（キーは設定ファイル側、実行先とモデルは DB 側） */
    private function configureJev(?string $key = 'ts-test'): void
    {
        $this->setApiKey(AiProvider::Jev, $key);
        AiSetting::sole()->update(['decision_provider' => AiProvider::Jev]);
        AiProviderConfig::create(['provider' => AiProvider::Jev, 'model_decision' => 'jev-1.13.0']);
    }

    /**
     * @return array{provider: string|null, ok: bool, message: string}
     */
    private function flashedResult(): array
    {
        $result = session('aiDecisionTest');
        $this->assertIsArray($result, 'flash aiDecisionTest が無い');

        return $result;
    }

    public function test_キー未設定なら判定エンジンに選べない(): void
    {
        $this->put(route('ai-decision.update'), ['provider' => 'jev', 'model' => 'jev-1.13.0'])
            ->assertSessionHasErrors('provider');

        $this->assertStringContainsString('TYPESAFE_API_KEY', session('errors')->first('provider'));
        $this->assertNull(AiSetting::sole()->decision_provider);
    }

    public function test_モデルが空なら判定エンジンに選べない(): void
    {
        $this->setApiKey(AiProvider::Jev, 'ts-test');

        $this->put(route('ai-decision.update'), ['provider' => 'jev', 'model' => ''])
            ->assertSessionHasErrors('model');

        $this->assertStringContainsString('判定用モデルが未設定です', session('errors')->first('model'));
        $this->assertNull(AiSetting::sole()->decision_provider);
    }

    public function test_キーとモデルがそろえば保存できる(): void
    {
        $this->setApiKey(AiProvider::Jev, 'ts-test');

        $this->put(route('ai-decision.update'), ['provider' => 'jev', 'model' => 'jev-1.13.0'])
            ->assertRedirect(route('settings.ai'))
            ->assertSessionHasNoErrors();

        $this->assertSame(AiProvider::Jev, AiSetting::sole()->decision_provider);
        $this->assertSame('jev-1.13.0', AiProviderConfig::where('provider', 'jev')->value('model_decision'));
    }

    public function test_保存しても同じ行の他の列には触らない(): void
    {
        $this->setApiKey(AiProvider::Jev, 'ts-test');
        AiProviderConfig::create(['provider' => AiProvider::Jev, 'model_decision' => 'jev-old', 'options' => ['analysis' => ['x' => 1]]]);

        $this->put(route('ai-decision.update'), ['provider' => 'jev', 'model' => 'jev-1.13.0'])->assertSessionHasNoErrors();

        $config = AiProviderConfig::where('provider', 'jev')->sole();
        $this->assertSame('jev-1.13.0', $config->model_decision);
        $this->assertSame(['analysis' => ['x' => 1]], $config->options);
    }

    public function test_空で保存するとオフになりモデルは残る(): void
    {
        $this->configureJev();

        $this->put(route('ai-decision.update'), ['provider' => '', 'model' => ''])
            ->assertRedirect(route('settings.ai'))
            ->assertSessionHasNoErrors();

        $this->assertNull(AiSetting::sole()->decision_provider);
        $this->assertSame('jev-1.13.0', AiProviderConfig::where('provider', 'jev')->value('model_decision'));
    }

    public function test_材料選抜のスイッチを保存できる(): void
    {
        $this->setApiKey(AiProvider::Jev, 'ts-test');

        $this->put(route('ai-decision.update'), ['provider' => 'jev', 'model' => 'jev-1.13.0', 'review_ordering' => true])
            ->assertSessionHasNoErrors();
        $this->assertTrue(AiSetting::sole()->decision_review_ordering);

        $this->put(route('ai-decision.update'), ['provider' => 'jev', 'model' => 'jev-1.13.0', 'review_ordering' => false])
            ->assertSessionHasNoErrors();
        $this->assertFalse(AiSetting::sole()->decision_review_ordering);
    }

    public function test_材料選抜を送らなければオフで保存する(): void
    {
        $this->configureJev();
        AiSetting::sole()->update(['decision_review_ordering' => true]);

        $this->put(route('ai-decision.update'), ['provider' => 'jev', 'model' => 'jev-1.13.0'])->assertSessionHasNoErrors();

        $this->assertFalse(AiSetting::sole()->decision_review_ordering);
    }

    public function test_判定エンジンをオフで保存すると材料選抜もオフに戻る(): void
    {
        $this->configureJev();
        AiSetting::sole()->update(['decision_review_ordering' => true]);

        // 画面は無効化したチェックの値もそのまま送ってくるが、オフでは効かないので戻す
        $this->put(route('ai-decision.update'), ['provider' => '', 'model' => '', 'review_ordering' => true])
            ->assertSessionHasNoErrors();

        $this->assertNull(AiSetting::sole()->decision_provider);
        $this->assertFalse(AiSetting::sole()->decision_review_ordering);
    }

    public function test_材料選抜は真偽値だけを受け付ける(): void
    {
        $this->setApiKey(AiProvider::Jev, 'ts-test');

        $this->put(route('ai-decision.update'), ['provider' => 'jev', 'model' => 'jev-1.13.0', 'review_ordering' => 'yes'])
            ->assertSessionHasErrors('review_ordering');

        $this->assertNull(AiSetting::sole()->decision_provider);
    }

    public function test_設定画面に材料選抜の状態が届く(): void
    {
        $this->configureJev();
        AiSetting::sole()->update(['decision_review_ordering' => true]);

        $this->get(route('settings.ai'))
            ->assertInertia(fn ($page) => $page->where('ai.decision.reviewOrdering', true));
    }

    public function test_面接メモを送る同意スイッチを保存できる(): void
    {
        $this->setApiKey(AiProvider::Jev, 'ts-test');

        $this->put(route('ai-decision.update'), ['provider' => 'jev', 'model' => 'jev-1.13.0', 'send_interview_notes' => true])
            ->assertSessionHasNoErrors();
        $this->assertTrue(AiSetting::sole()->decision_send_interview_notes);

        $this->put(route('ai-decision.update'), ['provider' => 'jev', 'model' => 'jev-1.13.0', 'send_interview_notes' => false])
            ->assertSessionHasNoErrors();
        $this->assertFalse(AiSetting::sole()->decision_send_interview_notes);
    }

    public function test_同意スイッチを送らなければオフで保存する(): void
    {
        $this->configureJev();
        AiSetting::sole()->update(['decision_send_interview_notes' => true]);

        $this->put(route('ai-decision.update'), ['provider' => 'jev', 'model' => 'jev-1.13.0'])->assertSessionHasNoErrors();

        $this->assertFalse(AiSetting::sole()->decision_send_interview_notes);
    }

    public function test_判定エンジンをオフで保存すると面接メモの同意スイッチもオフに戻る(): void
    {
        $this->configureJev();
        AiSetting::sole()->update(['decision_send_interview_notes' => true]);

        // 画面は無効化したチェックの値もそのまま送ってくるが、オフでは効かないので戻す
        $this->put(route('ai-decision.update'), ['provider' => '', 'model' => '', 'send_interview_notes' => true])
            ->assertSessionHasNoErrors();

        $this->assertNull(AiSetting::sole()->decision_provider);
        $this->assertFalse(AiSetting::sole()->decision_send_interview_notes);
    }

    public function test_面接メモの同意スイッチは真偽値だけを受け付ける(): void
    {
        $this->setApiKey(AiProvider::Jev, 'ts-test');

        $this->put(route('ai-decision.update'), ['provider' => 'jev', 'model' => 'jev-1.13.0', 'send_interview_notes' => 'yes'])
            ->assertSessionHasErrors('send_interview_notes');

        $this->assertNull(AiSetting::sole()->decision_provider);
    }

    public function test_設定画面に面接メモの同意スイッチの状態が届く(): void
    {
        $this->configureJev();
        AiSetting::sole()->update(['decision_send_interview_notes' => true]);

        $this->get(route('settings.ai'))
            ->assertInertia(fn ($page) => $page->where('ai.decision.sendInterviewNotes', true));
    }

    public function test_オフで保存するときは無効化されたモデル欄の値で止めない(): void
    {
        $this->configureJev();

        // 画面はオフの間モデル欄を編集できないまま送ってくるので、長すぎる値が残っていてもオフへの保存は通す
        $this->put(route('ai-decision.update'), ['provider' => '', 'model' => str_repeat('x', 121)])
            ->assertRedirect(route('settings.ai'))
            ->assertSessionHasNoErrors();

        $this->assertNull(AiSetting::sole()->decision_provider);
        $this->assertSame('jev-1.13.0', AiProviderConfig::where('provider', 'jev')->value('model_decision'));
    }

    public function test_判定エンジンを選ぶときはモデル名の長さを検査する(): void
    {
        $this->setApiKey(AiProvider::Jev, 'ts-test');

        $this->put(route('ai-decision.update'), ['provider' => 'jev', 'model' => str_repeat('x', 121)])
            ->assertSessionHasErrors('model');

        $this->assertNull(AiSetting::sole()->decision_provider);
    }

    public function test_判定専用でない実行先は選べない(): void
    {
        // Jev のキーも入れて候補を [jev] にする（候補が空のときの分岐はキー未設定のテストが見る）
        $this->setApiKey(AiProvider::Jev, 'ts-test');
        $this->setApiKey(AiProvider::OpenAi, 'sk-test');

        $this->put(route('ai-decision.update'), ['provider' => 'openai', 'model' => 'gpt-5'])
            ->assertSessionHasErrors('provider');

        $this->put(route('ai-decision.update'), ['provider' => 'codex', 'model' => 'x'])
            ->assertSessionHasErrors('provider');

        $this->assertNull(AiSetting::sole()->decision_provider);
        $this->assertSame(0, AiProviderConfig::where('provider', 'openai')->count());
    }

    public function test_キーの値はこの経路で受け取らない(): void
    {
        $this->setApiKey(AiProvider::Jev, 'ts-test');

        $this->put(route('ai-decision.update'), ['provider' => 'jev', 'model' => 'jev-1.13.0', 'api_key' => 'ts-leak'])
            ->assertSessionHasErrors('api_key');

        $this->assertNull(AiSetting::sole()->decision_provider);
    }

    public function test_接続テスト_未設定なら判定を呼ばずに実行先の選択を促す(): void
    {
        $this->post(route('ai-decision.test'))->assertRedirect(route('settings.ai'));

        $result = $this->flashedResult();
        $this->assertNull($result['provider']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('判定エンジンが未設定です', $result['message']);
        $this->assertSame(0, AiUsage::count());
    }

    public function test_接続テスト_キー未設定なら判定を呼ばずに変数名を示す(): void
    {
        // TestCase が束縛する ForbiddenAiDecider のまま。probe まで進めば例外の文言が出るので、進んでいないことも確かめられる
        $this->configureJev(null);

        $this->post(route('ai-decision.test'))->assertRedirect(route('settings.ai'));

        $result = $this->flashedResult();
        $this->assertSame('jev', $result['provider']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('.env の TYPESAFE_API_KEY が未設定です', $result['message']);
    }

    public function test_接続テスト_モデル未設定なら判定を呼ばない(): void
    {
        $this->setApiKey(AiProvider::Jev, 'ts-test');
        AiSetting::sole()->update(['decision_provider' => AiProvider::Jev]);

        $this->post(route('ai-decision.test'))->assertRedirect(route('settings.ai'));

        $result = $this->flashedResult();
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('判定用モデルが未設定です', $result['message']);
    }

    public function test_接続テスト_繋がればモデル名と入力トークンを添えて返す(): void
    {
        $this->configureJev();
        $usage = AiUsage::create([
            'provider' => AiProvider::Jev,
            'purpose' => AiPurpose::ConnectionTest,
            'model' => 'jev-1.13.0',
            'duration_ms' => 10,
            'prompt_chars' => 20,
            'output_chars' => 10,
            'input_tokens' => 57,
            'output_tokens' => 0,
            'succeeded' => true,
        ]);
        $decider = Mockery::mock(AiDecider::class);
        $decider->shouldReceive('probe')->once()->andReturn($usage);
        $this->app->instance(AiDecider::class, $decider);

        $this->post(route('ai-decision.test'))->assertRedirect(route('settings.ai'));

        $result = $this->flashedResult();
        $this->assertSame('jev', $result['provider']);
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('jev-1.13.0', $result['message']);
        $this->assertStringContainsString('入力 57 トークン', $result['message']);
        // 出力は無料なので出さない
        $this->assertStringNotContainsString('出力', $result['message']);
    }

    public function test_接続テスト_記録が残らなければその旨を出す(): void
    {
        $this->configureJev();
        $decider = Mockery::mock(AiDecider::class);
        $decider->shouldReceive('probe')->once()->andReturn(null);
        $this->app->instance(AiDecider::class, $decider);

        $this->post(route('ai-decision.test'));

        $result = $this->flashedResult();
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('使用量の記録が残っていません', $result['message']);
    }

    public function test_接続テスト_実行時の失敗は理由をそのまま出す(): void
    {
        $this->configureJev();
        $decider = Mockery::mock(AiDecider::class);
        $decider->shouldReceive('probe')->once()->andThrow(new \RuntimeException('Jev API エラー（HTTP 401）: invalid key'));
        $this->app->instance(AiDecider::class, $decider);

        $this->post(route('ai-decision.test'));

        $result = $this->flashedResult();
        $this->assertSame('jev', $result['provider']);
        $this->assertFalse($result['ok']);
        $this->assertSame('接続に失敗しました: Jev API エラー（HTTP 401）: invalid key', $result['message']);
    }

    public function test_接続テストの結果は画面の_flash_に届く(): void
    {
        $this->post(route('ai-decision.test'));

        $this->get(route('settings.ai'))
            ->assertInertia(fn ($page) => $page
                ->where('flash.aiDecisionTest.provider', null)
                ->where('flash.aiDecisionTest.ok', false));
    }

    public function test_設定画面に保存した判定エンジンが出る(): void
    {
        $this->setApiKey(AiProvider::Jev, 'ts-test');
        $this->put(route('ai-decision.update'), ['provider' => 'jev', 'model' => 'jev-1.13.0'])->assertSessionHasNoErrors();

        $this->get(route('settings.ai'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('ai.decision.provider', 'jev')
                ->where('ai.decision.available', ['jev'])
                ->where('ai.decision.providers.0.modelDecision', 'jev-1.13.0')
                ->where('flash.aiDecisionTest', null));
    }
}
