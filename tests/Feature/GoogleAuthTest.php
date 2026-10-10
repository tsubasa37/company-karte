<?php

namespace Tests\Feature;

use App\Models\GoogleCredential;
use App\Models\MailSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Gmail 取得のための一度きりの認可。
 * このアプリに画面ログインは無いので、確かめるのは「トークンを安全に受け取って保持できるか」。
 */
class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MailSetting::create([
            'google_client_id' => 'test.apps.googleusercontent.com',
            'google_client_secret' => 'test-secret',
        ]);
    }

    private function fakeTokenEndpoints(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-1',
                'refresh_token' => 'refresh-1',
                'expires_in' => 3600,
            ]),
            'www.googleapis.com/oauth2/v2/userinfo' => Http::response(['email' => 'me@example.com']),
        ]);
    }

    public function test_認可画面へのリダイレクトにoffline指定が入る(): void
    {
        $response = $this->get(route('google.redirect'));

        $response->assertRedirectContains('accounts.google.com');
        // リフレッシュトークンを受け取るために必須
        $response->assertRedirectContains('access_type=offline');
        $response->assertRedirectContains('gmail.readonly');
    }

    public function test_認可コールバックでリフレッシュトークンが保存される(): void
    {
        $this->fakeTokenEndpoints();
        $this->get(route('google.redirect'));
        $state = session('google_oauth_state');

        $this->get(route('google.callback', ['code' => 'auth-code', 'state' => $state]))
            ->assertRedirect(route('settings.mail'));

        $credential = GoogleCredential::firstOrFail();
        $this->assertSame('refresh-1', $credential->refresh_token);
        $this->assertSame('me@example.com', $credential->email);
    }

    public function test_stateが一致しないコールバックは拒否される(): void
    {
        $this->fakeTokenEndpoints();
        $this->get(route('google.redirect'));

        $this->get(route('google.callback', ['code' => 'auth-code', 'state' => 'wrong']))
            ->assertSessionHasErrors('google');

        $this->assertSame(0, GoogleCredential::count());
    }

    public function test_リフレッシュトークンが返らなければ例外になる(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-1',
                'expires_in' => 3600,
            ]),
        ]);
        $this->get(route('google.redirect'));
        $state = session('google_oauth_state');

        // 黙って「連携済み」にせず、原因が分かる形で失敗させる
        $this->withoutExceptionHandling();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('リフレッシュトークン');

        $this->get(route('google.callback', ['code' => 'auth-code', 'state' => $state]));
    }

    public function test_トークン取得の失敗は握り潰さない(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->get(route('google.redirect'));
        $state = session('google_oauth_state');

        $this->withoutExceptionHandling();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid_grant');

        $this->get(route('google.callback', ['code' => 'auth-code', 'state' => $state]));
    }

    public function test_連携を解除するとgoogle側のトークンも失効させる(): void
    {
        GoogleCredential::create(['refresh_token' => 'refresh-1', 'email' => 'me@example.com']);
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response('', 200)]);

        $this->delete(route('google.destroy'))->assertRedirect()->assertSessionDoesntHaveErrors();

        $this->assertSame(0, GoogleCredential::count());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/revoke') && $request['token'] === 'refresh-1');
    }

    public function test_既に失効済みのトークンでも解除できる(): void
    {
        GoogleCredential::create(['refresh_token' => 'refresh-1', 'email' => 'me@example.com']);
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response(['error' => 'invalid_token'], 400)]);

        $this->delete(route('google.destroy'))->assertSessionDoesntHaveErrors();

        $this->assertSame(0, GoogleCredential::count());
    }

    public function test_失効に失敗したら解除済みに見せず理由を出す(): void
    {
        GoogleCredential::create(['refresh_token' => 'refresh-1', 'email' => 'me@example.com']);
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response('server error', 500)]);

        $this->delete(route('google.destroy'))->assertSessionHasErrors('google');

        $this->assertSame(1, GoogleCredential::count(), 'Google 側で有効なまま手元だけ消さない');
    }

    public function test_再連携では古いトークンを失効させてから差し替える(): void
    {
        GoogleCredential::create(['refresh_token' => 'old-refresh', 'email' => 'me@example.com']);
        $this->fakeTokenEndpoints();
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response('', 200)]);
        $this->get(route('google.redirect'));
        $state = session('google_oauth_state');

        $this->get(route('google.callback', ['code' => 'auth-code', 'state' => $state]))
            ->assertRedirect(route('settings.mail'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/revoke') && $request['token'] === 'old-refresh');
        $this->assertSame('refresh-1', GoogleCredential::sole()->refresh_token);
    }

    public function test_設定画面に連携状態が渡る(): void
    {
        GoogleCredential::create(['refresh_token' => 'refresh-1', 'email' => 'me@example.com']);
        MailSetting::query()->update(['allowed_domains' => 'bizreach.jp']);

        $this->get(route('settings.mail'))
            ->assertInertia(fn ($page) => $page
                ->where('gmail.configured', true)
                ->where('gmail.connected', true)
                ->where('gmail.email', 'me@example.com')
                ->where('gmail.allowedDomains', ['bizreach.jp']));
    }
}
