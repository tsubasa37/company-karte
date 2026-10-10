<?php

namespace Tests\Feature;

use App\Actions\IngestClipAction;
use App\Services\Ai\ClipClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ClipListPageHintTest extends TestCase
{
    use RefreshDatabase;

    private function mockClassifier(bool $isMulti): void
    {
        $mock = Mockery::mock(ClipClassifier::class);
        $mock->shouldReceive('classify')->andReturn([
            'is_company_related' => false,
            'is_multi_company' => $isMulti,
            'company_name' => '',
            'source_type' => 'other',
            'summary' => '複数企業の求人が並ぶページ',
        ]);
        $this->app->instance(ClipClassifier::class, $mock);
    }

    public function test_一覧ページなら一括登録への案内フラグを返す(): void
    {
        Queue::fake();
        $this->mockClassifier(true);

        $result = $this->app->make(IngestClipAction::class)
            ->execute('求人検索結果', 'https://example.com/search', '複数企業のテキスト');

        $this->assertFalse($result['ok']);
        $this->assertSame('list_page', $result['reason']);
    }

    public function test_企業と無関係なページは通常のエラー(): void
    {
        Queue::fake();
        $this->mockClassifier(false);

        $result = $this->app->make(IngestClipAction::class)
            ->execute('ニュースまとめ', 'https://example.com/', '無関係なテキスト');

        $this->assertFalse($result['ok']);
        $this->assertSame('not_company', $result['reason']);
    }
}
