<?php

namespace Tests\Feature;

use App\Services\Ai\ClipClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ClipApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_トークン無しは401(): void
    {
        config(['services.clip.token' => 'secret-token']);

        $this->postJson('/api/clip', ['text' => 'テキスト'])
            ->assertStatus(401);
    }

    public function test_誤ったトークンは401(): void
    {
        config(['services.clip.token' => 'secret-token']);

        $this->postJson('/api/clip', ['text' => 'テキスト'], ['X-Clip-Token' => 'wrong'])
            ->assertStatus(401);
    }

    public function test_危険なスキームのurlは422で拒否される(): void
    {
        config(['services.clip.token' => 'secret-token']);

        foreach (['javascript:alert(1)', 'data:text/html,x', 'view-source://example.com'] as $url) {
            $this->postJson('/api/clip', [
                'text' => '本文',
                'url' => $url,
            ], ['X-Clip-Token' => 'secret-token'])
                ->assertStatus(422)
                ->assertJsonValidationErrors('url');
        }
    }

    public function test_一括登録apiでも危険なスキームのurlは422で拒否される(): void
    {
        config(['services.clip.token' => 'secret-token']);

        $this->postJson('/api/clip-list', [
            'text' => '本文',
            'url' => 'javascript:alert(1)',
        ], ['X-Clip-Token' => 'secret-token'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');
    }

    public function test_記号を含むurlの422は原因url付きのメッセージを返す(): void
    {
        config(['services.clip.token' => 'secret-token']);

        // Chrome の location.href は「|」や空白をエンコードしないまま送ってくることがある
        $this->postJson('/api/clip', [
            'text' => '本文',
            'url' => 'https://example.com/search?q=年収|東京 リモート',
        ], ['X-Clip-Token' => 'secret-token'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url')
            ->assertJsonPath('errors.url.0', fn (string $message) => str_contains($message, 'URLの形式が不正です')
                && str_contains($message, 'https://example.com/search?q=年収|東京 リモート'));
    }

    public function test_httpsのurlは通る(): void
    {
        Queue::fake();
        config(['services.clip.token' => 'secret-token']);

        // 拡張は location.href を送るため、正常系（http/https）が壊れないことを確かめる
        $mock = Mockery::mock(ClipClassifier::class);
        $mock->shouldReceive('classify')->andReturn([
            'is_company_related' => true,
            'is_multi_company' => false,
            'company_name' => '株式会社テスト',
            'source_type' => 'review',
            'summary' => 'テスト社の口コミ',
        ]);
        $this->app->instance(ClipClassifier::class, $mock);

        $this->postJson('/api/clip', [
            'text' => '口コミ本文',
            'url' => 'https://www.openwork.jp/company_answer.php?m_id=a123&q_no=1',
        ], ['X-Clip-Token' => 'secret-token'])
            ->assertOk()
            ->assertJson(['ok' => true]);
    }
}
