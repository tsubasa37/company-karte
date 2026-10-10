<?php

namespace Tests\Feature;

use App\Models\GoogleCredential;
use App\Models\MailSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 設定画面の「接続をテスト」。
 *
 * 保存できたことと実際に取得できることは別なので、失敗の理由を切り分けて出せるかを確かめる。
 * 「繋がらないのに黙って何も言わない」状態を作らないことが要点。
 */
class GmailConnectionTestTest extends TestCase
{
    use RefreshDatabase;

    private function configureClient(): void
    {
        MailSetting::create([
            'google_client_id' => 'test.apps.googleusercontent.com',
            'google_client_secret' => 'test-secret',
            'allowed_domains' => 'bizreach.jp',
        ]);
    }

    private function connect(): void
    {
        GoogleCredential::create([
            'refresh_token' => 'refresh-1',
            'access_token' => 'access-1',
            'access_token_expires_at' => now()->addHour(),
        ]);
    }

    public function test_クライアント未登録なら登録を促す(): void
    {
        $this->post(route('mail-settings.test'))->assertRedirect();

        $this->get(route('settings.mail'))
            ->assertInertia(fn ($page) => $page
                ->where('flash.gmailTest.ok', false)
                ->where('flash.gmailTest.message', fn (string $m) => str_contains($m, 'クライアントID')));
    }

    public function test_未連携ならログインを促す(): void
    {
        $this->configureClient();

        $this->post(route('mail-settings.test'))->assertRedirect();

        $this->get(route('settings.mail'))
            ->assertInertia(fn ($page) => $page
                ->where('flash.gmailTest.ok', false)
                ->where('flash.gmailTest.message', fn (string $m) => str_contains($m, 'まだ連携していません')));
    }

    public function test_許可ドメイン未設定なら取り込めないことを伝える(): void
    {
        $this->configureClient();
        MailSetting::query()->update(['allowed_domains' => '']);
        $this->connect();

        $this->post(route('mail-settings.test'))->assertRedirect();

        $this->get(route('settings.mail'))
            ->assertInertia(fn ($page) => $page
                ->where('flash.gmailTest.ok', false)
                ->where('flash.gmailTest.message', fn (string $m) => str_contains($m, '1通も取り込みません')));
    }

    public function test_該当メールがあれば件数を返す(): void
    {
        $this->configureClient();
        $this->connect();
        Http::fake(['gmail.googleapis.com/*' => Http::response([
            'messages' => [['id' => 'a'], ['id' => 'b']],
        ])]);

        $this->post(route('mail-settings.test'))->assertRedirect();

        $this->get(route('settings.mail'))
            ->assertInertia(fn ($page) => $page
                ->where('flash.gmailTest.ok', true)
                ->where('flash.gmailTest.message', fn (string $m) => str_contains($m, '2件')));
    }

    public function test_該当0件なら繋がった上で条件を見直すよう伝える(): void
    {
        $this->configureClient();
        $this->connect();
        Http::fake(['gmail.googleapis.com/*' => Http::response(['messages' => []])]);

        $this->post(route('mail-settings.test'))->assertRedirect();

        // 繋がってはいるので ok は true。ただし0件の理由に触れる
        $this->get(route('settings.mail'))
            ->assertInertia(fn ($page) => $page
                ->where('flash.gmailTest.ok', true)
                ->where('flash.gmailTest.message', fn (string $m) => str_contains($m, '0件')));
    }

    public function test_認可切れの理由をそのまま画面に出す(): void
    {
        $this->configureClient();
        $this->connect();
        // 公開ステータスが「テスト」のままだと7日で起きる失敗
        Http::fake(['gmail.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 401)]);

        $this->post(route('mail-settings.test'))->assertRedirect();

        $this->get(route('settings.mail'))
            ->assertInertia(fn ($page) => $page
                ->where('flash.gmailTest.ok', false)
                ->where('flash.gmailTest.message', fn (string $m) => str_contains($m, 'invalid_grant')));
    }

    public function test_テストは本文を取得しない(): void
    {
        $this->configureClient();
        $this->connect();
        Http::fake(['gmail.googleapis.com/*' => Http::response(['messages' => [['id' => 'a']]])]);

        $this->post(route('mail-settings.test'));

        // 確認のために本文取得（＝codex実行の入口）まで走らせない
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/messages/a'));
    }
}
