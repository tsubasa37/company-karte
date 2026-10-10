<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * バリデーション失敗時の old input に、暗号化して保存している値を残さない。
 * セッションは SQLite（バックアップにも入る）に置かれるため、素通りすると暗号化の意味が無くなる。
 */
class OldInputSecretsTest extends TestCase
{
    use RefreshDatabase;

    public function test_gmail設定の貼り間違いで秘密の値がセッションに残らない(): void
    {
        // クライアントIDの形式違いで弾かれる（Gmail 設定は4項目を1回で送る）
        $response = $this->from(route('settings.mail'))->put(route('mail-settings.update'), [
            'google_client_id' => 'wrong-format',
            'google_client_secret' => 'my-secret',
            'allowed_domains' => 'bizreach.jp',
            'mask_words' => '山田太郎',
        ]);

        $response->assertSessionHasErrors('google_client_id');
        $response->assertSessionHas('_old_input.allowed_domains', 'bizreach.jp');
        $response->assertSessionMissing('_old_input.google_client_id');
        $response->assertSessionMissing('_old_input.google_client_secret');
        $response->assertSessionMissing('_old_input.mask_words');
    }
}
