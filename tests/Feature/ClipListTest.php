<?php

namespace Tests\Feature;

use App\Actions\IngestClipListAction;
use App\Models\Company;
use App\Services\Ai\CompanyListExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ClipListTest extends TestCase
{
    use RefreshDatabase;

    private function mockExtractor(array $companies): void
    {
        $mock = Mockery::mock(CompanyListExtractor::class);
        $mock->shouldReceive('extract')->andReturn($companies);
        $this->app->instance(CompanyListExtractor::class, $mock);
    }

    public function test_未登録の企業だけ一括登録される(): void
    {
        Company::create(['name' => '株式会社テスト']);
        $this->mockExtractor(['テスト', '株式会社新規メーカー', '新規商事株式会社']);

        $result = $this->app->make(IngestClipListAction::class)
            ->execute('お気に入り一覧', 'https://example.com/likes', '一覧ページテキスト');

        $this->assertTrue($result['ok']);
        $this->assertSame(['株式会社新規メーカー', '新規商事株式会社'], $result['created']);
        $this->assertSame(['テスト'], $result['existing'], '法人格の表記ゆれも既存として名寄せされる');
        $this->assertSame(3, Company::count());
    }

    public function test_企業名が抽出できない場合は失敗を返す(): void
    {
        $this->mockExtractor([]);

        $result = $this->app->make(IngestClipListAction::class)
            ->execute('タイトル', 'https://example.com', 'ただの記事ページ');

        $this->assertFalse($result['ok']);
        $this->assertSame(0, Company::count());
    }

    public function test_一括登録_ap_iもトークン必須(): void
    {
        config(['services.clip.token' => 'secret-token']);

        $this->postJson('/api/clip-list', ['text' => 'テキスト'])
            ->assertStatus(401);
    }
}
