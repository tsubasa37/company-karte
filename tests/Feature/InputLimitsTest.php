<?php

namespace Tests\Feature;

use App\Actions\IngestClipListAction;
use App\Models\Company;
use App\Services\Ai\CompanyListExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * 外部由来の入力（ユーザー入力・AI出力・ページ本文）に掛けた上限の確認。
 *
 * いずれも「巨大な入力・大量の生成物を1リクエストで通さない」ための歯止めで、
 * 通常の利用（実在ページの本文・実在する企業一覧）は妨げない値にしてある。
 */
class InputLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function mockExtractor(array $companies): void
    {
        $mock = Mockery::mock(CompanyListExtractor::class);
        $mock->shouldReceive('extract')->andReturn($companies);
        $this->app->instance(CompanyListExtractor::class, $mock);
    }

    public function test_公式数値の出典urlはhttp以外のスキームを拒否する(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        // この値は画面で <a href> になる。素の url ルールは data: blob: を通すため
        // 他のURL項目と同じ url:http,https に揃えてある
        foreach (['javascript:alert(1)', 'data://text/html;base64,PHA+', 'blob://x/y'] as $bad) {
            $this->post("/companies/{$company->id}/metrics", [
                'source' => 'shokuba',
                'key' => '平均年収',
                'value' => '600',
                'source_url' => $bad,
            ])->assertSessionHasErrors('source_url');
        }

        $this->assertSame(0, $company->officialMetrics()->count());
    }

    public function test_公式数値の出典urlはhttpsを受け付ける(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        $this->post("/companies/{$company->id}/metrics", [
            'source' => 'shokuba',
            'key' => '平均年収',
            'value' => '600',
            'source_url' => 'https://example.com/ir',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $company->officialMetrics()->count());
    }

    public function test_クリップapiは長すぎる本文を拒否する(): void
    {
        config(['services.clip.token' => 'secret-token']);

        // 上限（200万字）を1字超える本文
        $this->postJson('/api/clip', ['text' => str_repeat('あ', 2000001)], [
            'X-Clip-Token' => 'secret-token',
        ])->assertStatus(422)->assertJsonValidationErrors('text');
    }

    public function test_一括登録は件数の上限で打ち切り件数を返す(): void
    {
        // AI の出力なので件数を信用しない。上限を超えた分は黙って捨てず skipped で返す
        $names = array_map(fn (int $i) => "株式会社テスト{$i}", range(1, 130));
        $this->mockExtractor($names);

        $result = $this->app->make(IngestClipListAction::class)
            ->execute('お気に入り一覧', 'https://example.com/likes', '一覧ページテキスト');

        $this->assertTrue($result['ok']);
        $this->assertCount(100, $result['created']);
        $this->assertSame(30, $result['skipped']);
        $this->assertSame(100, Company::count());
    }

    public function test_一括登録は長すぎる社名を切り詰める(): void
    {
        $this->mockExtractor([str_repeat('あ', 300)]);

        $result = $this->app->make(IngestClipListAction::class)
            ->execute('お気に入り一覧', 'https://example.com/likes', '一覧ページテキスト');

        $this->assertTrue($result['ok']);
        $this->assertSame(255, mb_strlen($result['created'][0]));
        $this->assertSame(255, mb_strlen((string) Company::first()->name));
    }
}
