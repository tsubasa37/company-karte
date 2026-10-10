<?php

namespace Tests\Feature;

use App\Actions\IngestClipAction;
use App\Enums\SourceType;
use App\Jobs\SplitReviewSourceJob;
use App\Models\Company;
use App\Models\SourceDocument;
use App\Services\Ai\ClipClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ClipTypeOverrideTest extends TestCase
{
    use RefreshDatabase;

    /** AIは news と判定する想定（ボタン指定が勝つことを確かめるため） */
    private function mockClassifier(): void
    {
        $mock = Mockery::mock(ClipClassifier::class);
        $mock->shouldReceive('classify')->andReturn([
            'is_company_related' => true,
            'is_multi_company' => false,
            'company_name' => '株式会社テスト',
            'source_type' => 'news',
            'summary' => 'テスト',
        ]);
        $this->app->instance(ClipClassifier::class, $mock);
    }

    public function test_ボタンで選んだ種別が_a_i判定より優先される(): void
    {
        Queue::fake();
        $this->mockClassifier();

        $result = $this->app->make(IngestClipAction::class)
            ->execute('口コミページ', 'https://example.com/r', '口コミ本文', 'review');

        $this->assertTrue($result['ok']);
        $this->assertSame('review', $result['source_type']);
        $this->assertSame(SourceType::Review, SourceDocument::firstOrFail()->type);
        Queue::assertPushed(SplitReviewSourceJob::class, 1);
    }

    public function test_種別未指定なら_a_iの判定を使う(): void
    {
        Queue::fake();
        $this->mockClassifier();

        $result = $this->app->make(IngestClipAction::class)
            ->execute('記事', 'https://example.com/n', '記事本文');

        $this->assertSame('news', $result['source_type']);
        $this->assertSame(SourceType::News, SourceDocument::firstOrFail()->type);
        Queue::assertNotPushed(SplitReviewSourceJob::class);
    }

    public function test_ap_iでも種別を指定できる(): void
    {
        Queue::fake();
        config(['services.clip.token' => 'secret-token']);
        $this->mockClassifier();

        $this->postJson('/api/clip', [
            'text' => '口コミ本文',
            'url' => 'https://example.com/r',
            'type' => 'review',
        ], ['X-Clip-Token' => 'secret-token'])
            ->assertOk()
            ->assertJson(['ok' => true, 'source_type' => 'review']);
    }

    public function test_不正な種別はバリデーションで弾く(): void
    {
        config(['services.clip.token' => 'secret-token']);

        $this->postJson('/api/clip', [
            'text' => '本文',
            'type' => 'hacked-type',
        ], ['X-Clip-Token' => 'secret-token'])
            ->assertStatus(422);
    }

    public function test_投入先の企業を指定したクリップは前段の機械判定を飛ばすよう伝える(): void
    {
        Queue::fake();
        $company = Company::create(['name' => '株式会社テスト']);
        $mock = Mockery::mock(ClipClassifier::class);
        $mock->shouldReceive('classify')
            ->once()
            ->with('求人ページ', 'https://example.com/j', '本文', true)
            ->andReturn([
                'is_company_related' => true,
                'is_multi_company' => false,
                'company_name' => '別の社名',
                'source_type' => 'jobposting',
                'summary' => '求人票',
            ]);
        $this->app->instance(ClipClassifier::class, $mock);

        $result = $this->app->make(IngestClipAction::class)
            ->execute('求人ページ', 'https://example.com/j', '本文', null, $company->id);

        $this->assertTrue($result['ok']);
        $this->assertSame($company->id, $result['company_id']);
    }
}
