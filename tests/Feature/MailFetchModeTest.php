<?php

namespace Tests\Feature;

use App\Actions\BuildGmailStatusAction;
use App\Models\GoogleCredential;
use App\Models\MailSetting;
use App\Services\Mail\MailAutoFetch;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 取り込み方法（自動=15分おき / 手動=ボタン）の切り替え。
 *
 * 要点は「既定が手動であること」と「手動の間はスケジューラが1回も走らないこと」。
 * 自動にしていないのに取得と抽出（AI）が走ると、押していないのに課金される状態になる。
 */
class MailFetchModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MailSetting::create([
            'google_client_id' => 'test.apps.googleusercontent.com',
            'google_client_secret' => 'test-secret',
            'allowed_domains' => 'bizreach.jp',
        ]);
        config([
            'services.mail_ingest.window_days' => 7,
            'services.mail_ingest.max_messages' => 20,
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

    public function test_既定は手動で自動取得は無効(): void
    {
        $this->connect();

        $this->assertFalse(MailSetting::sole()->auto_fetch);
        $this->assertFalse(app(MailAutoFetch::class)->isEnabled());
        $this->assertFalse(app(MailAutoFetch::class)->isActive());
        $this->assertFalse(app(BuildGmailStatusAction::class)->execute()['autoFetch']);
    }

    public function test_画面から自動に切り替えるとスケジューラの条件が満たされる(): void
    {
        $this->connect();

        $this->put(route('mail-settings.auto-fetch'), ['auto_fetch' => true])
            ->assertRedirect(route('settings.mail'));

        $this->assertTrue(MailSetting::sole()->auto_fetch);
        $this->assertTrue(app(MailAutoFetch::class)->isEnabled());
        $this->assertTrue(app(MailAutoFetch::class)->isActive());
    }

    public function test_自動でも未連携なら実際には走らない状態として扱う(): void
    {
        // 連携していなければ FetchMailAction が例外で止まる。画面の説明文もその事実に合わせる
        MailSetting::sole()->update(['auto_fetch' => true]);

        $this->assertTrue(app(MailAutoFetch::class)->isEnabled());
        $this->assertFalse(app(MailAutoFetch::class)->isActive());
    }

    /** スケジューラに登録された mail:fetch の実行条件（->when）を取り出す */
    private function schedulePasses(): bool
    {
        $event = collect(app(Schedule::class)->events())
            ->sole(fn ($event) => str_contains($event->command ?? '', 'mail:fetch'));

        return $event->filtersPass(app());
    }

    public function test_手動の間はスケジューラが実行条件を満たさない(): void
    {
        // 15分ごとの時刻が来ても、この条件が false なら mail:fetch は起動しない
        $this->connect();

        $this->assertFalse($this->schedulePasses());

        MailSetting::sole()->update(['auto_fetch' => true]);

        $this->assertTrue($this->schedulePasses());
    }

    public function test_未連携でも取り込み方法を切り替えられる(): void
    {
        // 「勝手に取りに行かれるのか」は連携を決める前に選べる必要がある
        $this->put(route('mail-settings.auto-fetch'), ['auto_fetch' => true])
            ->assertRedirect(route('settings.mail'));

        $this->assertTrue(app(BuildGmailStatusAction::class)->execute()['autoFetch']);
    }

    public function test_設定行が無い状態でも取り込み方法を保存できる(): void
    {
        MailSetting::query()->delete();

        $this->put(route('mail-settings.auto-fetch'), ['auto_fetch' => true])
            ->assertRedirect(route('settings.mail'));

        $this->assertTrue(MailSetting::sole()->auto_fetch);
    }

    public function test_手動のボタンは自動が無効でも取得できる(): void
    {
        Queue::fake();
        $this->connect();
        Http::fake(['gmail.googleapis.com/*' => Http::response(['messages' => []])]);

        $this->post(route('mail-settings.fetch'))
            ->assertRedirect(route('settings.mail'))
            ->assertSessionHas('mailFetch.ok', true);

        Http::assertSentCount(1);
    }

    public function test_未連携で手動取得を押しても通信せず理由を返す(): void
    {
        Http::fake();

        $this->post(route('mail-settings.fetch'))
            ->assertRedirect(route('settings.mail'))
            ->assertSessionHas('mailFetch.ok', false);

        Http::assertNothingSent();
    }

    public function test_ドメイン未設定で手動取得を押しても通信せず理由を返す(): void
    {
        Http::fake();
        $this->connect();
        MailSetting::sole()->update(['allowed_domains' => null]);

        $this->post(route('mail-settings.fetch'))
            ->assertRedirect(route('settings.mail'))
            ->assertSessionHas('mailFetch.ok', false);

        Http::assertNothingSent();
    }

    public function test_自動取得の状態がエンジン設定画面に渡る(): void
    {
        $this->connect();

        $this->get(route('settings.ai'))
            ->assertInertia(fn ($page) => $page->where('mailAutoFetch', false));

        $this->put(route('mail-settings.auto-fetch'), ['auto_fetch' => true]);

        $this->get(route('settings.ai'))
            ->assertInertia(fn ($page) => $page->where('mailAutoFetch', true));
    }
}
