<?php

namespace Tests\Feature;

use App\Models\MailSetting;
use App\Services\Mail\FromDomainAllowList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Gmail連携の設定を画面から入力する経路。
 * 秘密の値が「暗号化されること」「画面へ返らないこと」が要点。
 */
class MailSettingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'google_client_id' => '1234.apps.googleusercontent.com',
            'google_client_secret' => 'GOCSPX-secret',
            'allowed_domains' => 'bizreach.jp,doda.jp',
            'mask_words' => '山田太郎',
        ];
    }

    public function test_設定を画面から保存できる(): void
    {
        $this->put(route('mail-settings.update'), $this->payload())->assertRedirect();

        $setting = MailSetting::firstOrFail();
        $this->assertSame('1234.apps.googleusercontent.com', $setting->google_client_id);
        $this->assertSame('GOCSPX-secret', $setting->google_client_secret);
        $this->assertSame('bizreach.jp,doda.jp', $setting->allowed_domains);
    }

    public function test_秘密の値はデータベース上で暗号化される(): void
    {
        $this->put(route('mail-settings.update'), $this->payload())->assertRedirect();

        // バックアップファイルが持ち出されても、そのままでは使えない状態にする
        $raw = DB::table('mail_settings')->first();
        $this->assertNotSame('GOCSPX-secret', $raw->google_client_secret);
        $this->assertNotSame('山田太郎', $raw->mask_words);
        // 許可ドメインは秘密ではないので素のまま（暗号化の対象を広げすぎない）
        $this->assertSame('bizreach.jp,doda.jp', $raw->allowed_domains);
    }

    public function test_クライアントシークレットは画面へ返らない(): void
    {
        $this->put(route('mail-settings.update'), $this->payload());

        $this->get(route('settings.mail'))
            ->assertInertia(fn ($page) => $page
                ->where('gmail.clientId', '1234.apps.googleusercontent.com')
                ->where('gmail.hasClientSecret', true)
                ->missing('gmail.clientSecret'));
    }

    public function test_シークレットを空で送ると既存の値が据え置かれる(): void
    {
        $this->put(route('mail-settings.update'), $this->payload());

        // ドメインだけ直したいときにシークレットを貼り直させない
        $this->put(route('mail-settings.update'), $this->payload([
            'google_client_secret' => '',
            'allowed_domains' => 'green.jp',
        ]))->assertRedirect();

        $setting = MailSetting::firstOrFail();
        $this->assertSame('GOCSPX-secret', $setting->google_client_secret);
        $this->assertSame('green.jp', $setting->allowed_domains);
    }

    public function test_未登録ならシークレットは必須(): void
    {
        $this->put(route('mail-settings.update'), $this->payload(['google_client_secret' => '']))
            ->assertSessionHasErrors('google_client_secret');

        $this->assertSame(0, MailSetting::count());
    }

    public function test_クライアント_i_dの形式違いは弾かれる(): void
    {
        // シークレットと取り違えて貼るミスをここで止める
        $this->put(route('mail-settings.update'), $this->payload(['google_client_id' => 'GOCSPX-secret']))
            ->assertSessionHasErrors('google_client_id');

        $this->assertSame(0, MailSetting::count());
    }

    public function test_リダイレクト_ur_iはルートから生成されて画面に出る(): void
    {
        // 手入力させると Google 側の登録値とズレて認可が失敗するため、生成した値を見せる
        $this->get(route('settings.mail'))
            ->assertInertia(fn ($page) => $page->where('gmail.redirectUri', route('google.callback')));
    }

    public function test_保存した設定が取り込みの許可ドメインに効く(): void
    {
        $this->put(route('mail-settings.update'), $this->payload(['allowed_domains' => 'bizreach.jp']));

        $allowList = app(FromDomainAllowList::class);
        $this->assertTrue($allowList->allows('scout.bizreach.jp'));
        $this->assertFalse($allowList->allows('spam.example.net'));
    }
}
