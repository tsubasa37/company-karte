<?php

namespace Tests\Feature;

use App\Actions\SplitReviewSourceAction;
use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Jobs\ExtractConditionsJob;
use App\Jobs\SplitReviewSourceJob;
use App\Models\Company;
use App\Models\ReviewItem;
use App\Models\SourceDocument;
use App\Services\Ai\ReviewSplitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SourceDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function review(Company $company, string $url): SourceDocument
    {
        return $company->sourceDocuments()->create([
            'type' => SourceType::Review,
            'origin' => SourceOrigin::Url,
            'url' => $url,
            'raw_text' => '口コミページ本文',
        ]);
    }

    /** @param  array<int, array<string, string>>  ...$batches */
    private function mockSplitter(array ...$batches): void
    {
        $mock = Mockery::mock(ReviewSplitter::class);
        $mock->shouldReceive('split')->andReturn(...$batches);
        $this->app->instance(ReviewSplitter::class, $mock);
    }

    public function test_口コミソース削除で残りのソースが再分割される(): void
    {
        Queue::fake();
        $company = Company::create(['name' => '株式会社テスト']);
        $a = $this->review($company, 'https://example.com/a');
        $b = $this->review($company, 'https://example.com/b');
        $b->update(['split_at' => now()]);

        $this->delete(route('sources.destroy', $a))->assertRedirect();

        $this->assertNull($b->fresh()->split_at, '残りのソースは未分割に戻る');
        Queue::assertPushed(SplitReviewSourceJob::class, fn ($job) => $job->sourceDocumentId === $b->id);
    }

    public function test_重複口コミがソース削除後も再分割で復元される(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $a = $this->review($company, 'https://example.com/a');
        $b = $this->review($company, 'https://example.com/b');

        // 同じ口コミが両方のページに載っている状態を作る（Aにのみ紐づく）
        $review = [
            'body' => '残業が多い',
            'stance' => '退職',
            'posted_period' => '',
            'tenure_period' => '',
            'category' => '',
            'job_type' => '',
        ];
        $this->mockSplitter([$review], [$review], [$review]);
        $splitAction = $this->app->make(SplitReviewSourceAction::class);
        $splitAction->execute($a);
        $splitAction->execute($b);

        $this->assertSame($a->id, ReviewItem::firstOrFail()->source_document_id, '重複口コミは先に分割したAに紐づく');

        // A を削除すると cascade で口コミが消えるが、B の再分割で復元される
        $this->delete(route('sources.destroy', $a))->assertRedirect();
        $splitAction->execute($b->fresh());

        $this->assertSame(1, ReviewItem::count());
        $this->assertSame($b->id, ReviewItem::firstOrFail()->source_document_id, 'B由来として復元される');
    }

    public function test_求人票削除で条件抽出ジョブが投入される(): void
    {
        Queue::fake();
        $company = Company::create(['name' => '株式会社テスト']);
        $source = $company->sourceDocuments()->create([
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '求人票テキスト',
        ]);

        $this->delete(route('sources.destroy', $source))->assertRedirect();

        Queue::assertPushed(ExtractConditionsJob::class);
        Queue::assertNotPushed(SplitReviewSourceJob::class);
    }
}
