<?php

namespace Tests\Feature;

use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Jobs\ExtractConditionsJob;
use App\Jobs\SplitReviewSourceJob;
use App\Models\Company;
use App\Models\ReviewItem;
use App\Models\SourceDocument;
use App\Models\SourceRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SourceUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake(); // 派生ジョブ（口コミ分割・条件抽出）は codex を呼ぶため実行させない
    }

    private function source(Company $company, SourceType $type, ?string $url = 'https://example.com/a'): SourceDocument
    {
        return $company->sourceDocuments()->create([
            'type' => $type,
            'origin' => $url === null ? SourceOrigin::Paste : SourceOrigin::Url,
            'url' => $url,
            'raw_text' => '取り込んだ本文',
        ]);
    }

    private function reviewItem(Company $company, SourceDocument $source): ReviewItem
    {
        return ReviewItem::create([
            'company_id' => $company->id,
            'source_document_id' => $source->id,
            'body' => '残業が多い',
            'body_hash' => sha1('残業が多い'),
        ]);
    }

    public function test_口コミから求人票へ種別変更すると分割結果が消えて条件抽出が走る(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $source = $this->source($company, SourceType::Review);
        $source->update(['split_at' => now()]);
        $this->reviewItem($company, $source);

        $this->patch(route('sources.update', $source), [
            'type' => 'jobposting',
            'company_id' => $company->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(SourceType::JobPosting, $source->fresh()->type);
        $this->assertNull($source->fresh()->split_at, '分割状態はリセットされる');
        $this->assertSame(0, ReviewItem::count(), '口コミ由来の分割結果は掃除される');
        Queue::assertPushed(ExtractConditionsJob::class);
    }

    public function test_求人票から口コミへ種別変更すると分割ジョブと条件抽出が走る(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $source = $this->source($company, SourceType::JobPosting);

        $this->patch(route('sources.update', $source), [
            'type' => 'review',
            'company_id' => $company->id,
        ])->assertSessionHasNoErrors();

        Queue::assertPushed(SplitReviewSourceJob::class, fn ($job) => $job->sourceDocumentId === $source->id);
        // 求人票が1本減るので、残りの求人票から労働条件を抽出し直す
        Queue::assertPushed(ExtractConditionsJob::class, fn ($job) => $job->companyId === $company->id);
    }

    public function test_残りの口コミソースも再分割される(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $source = $this->source($company, SourceType::Review);
        $other = $this->source($company, SourceType::Review, 'https://example.com/b');
        $other->update(['split_at' => now()]);

        $this->patch(route('sources.update', $source), [
            'type' => 'other',
            'company_id' => $company->id,
        ])->assertSessionHasNoErrors();

        // 重複掲載の口コミが消えたソースにだけ紐づいていた可能性があるため作り直す
        $this->assertNull($other->fresh()->split_at);
        Queue::assertPushed(SplitReviewSourceJob::class, fn ($job) => $job->sourceDocumentId === $other->id);
    }

    public function test_企業を付け替えると旧企業のソース一覧から消える(): void
    {
        $from = Company::create(['name' => 'A社']);
        $to = Company::create(['name' => 'B社']);
        $source = $this->source($from, SourceType::JobPosting);

        $this->patch(route('sources.update', $source), [
            'type' => 'jobposting',
            'company_id' => $to->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($to->id, $source->fresh()->company_id);
        $this->get(route('companies.show', $from))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('sources', 0));
        $this->get(route('companies.show', $to))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('sources', 1));

        // 旧企業・新企業の両方で労働条件を抽出し直す
        Queue::assertPushed(ExtractConditionsJob::class, fn ($job) => $job->companyId === $from->id);
        Queue::assertPushed(ExtractConditionsJob::class, fn ($job) => $job->companyId === $to->id);
    }

    public function test_企業付け替えで口コミの分割結果は作り直される(): void
    {
        $from = Company::create(['name' => 'A社']);
        $to = Company::create(['name' => 'B社']);
        $source = $this->source($from, SourceType::Review);
        $this->reviewItem($from, $source);

        $this->patch(route('sources.update', $source), [
            'type' => 'review',
            'company_id' => $to->id,
        ])->assertSessionHasNoErrors();

        // 分割済み口コミは旧企業に紐づいているため消し、新企業として分割し直す
        $this->assertSame(0, ReviewItem::count());
        Queue::assertPushed(SplitReviewSourceJob::class, fn ($job) => $job->sourceDocumentId === $source->id);
    }

    public function test_変更先に同じ_ur_lと種別のソースがあればエラーで止める(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $source = $this->source($company, SourceType::Review);
        $this->source($company, SourceType::JobPosting);

        $this->patch(route('sources.update', $source), [
            'type' => 'jobposting',
            'company_id' => $company->id,
        ])->assertInvalid(['type']);

        $this->assertSame(SourceType::Review, $source->fresh()->type, '衝突時は変更しない');
        Queue::assertNothingPushed();
    }

    public function test_面接メモの本文編集で履歴が積まれる(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $source = $this->source($company, SourceType::Interview, null);

        $this->patch(route('sources.update', $source), [
            'type' => 'interview',
            'company_id' => $company->id,
            'raw_text' => "取り込んだ本文\n2次面接: 逆質問への回答が具体的だった",
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, SourceRevision::count(), '編集前の本文は必ず履歴に残す');
        $this->assertSame('取り込んだ本文', SourceRevision::firstOrFail()->raw_text);
        $this->assertStringContainsString('逆質問', $source->fresh()->raw_text);
    }

    public function test_面接メモ以外の本文編集は弾く(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $source = $this->source($company, SourceType::JobPosting);

        $this->patch(route('sources.update', $source), [
            'type' => 'jobposting',
            'company_id' => $company->id,
            'raw_text' => '書き換えた求人票',
        ])->assertInvalid(['raw_text']);

        $this->assertSame('取り込んだ本文', $source->fresh()->raw_text);
        $this->assertSame(0, SourceRevision::count());
    }

    public function test_存在しない企業への付け替えは弾く(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $source = $this->source($company, SourceType::JobPosting);

        $this->patch(route('sources.update', $source), [
            'type' => 'jobposting',
            'company_id' => 999,
        ])->assertInvalid(['company_id']);

        $this->assertSame($company->id, $source->fresh()->company_id);
    }
}
