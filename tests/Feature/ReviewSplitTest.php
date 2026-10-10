<?php

namespace Tests\Feature;

use App\Actions\IngestClipAction;
use App\Actions\SplitReviewSourceAction;
use App\Enums\AnalysisStep;
use App\Enums\RunStatus;
use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Jobs\AnalyzeCompanyJob;
use App\Jobs\SplitReviewSourceJob;
use App\Models\Company;
use App\Models\ReviewItem;
use App\Models\SourceDocument;
use App\Services\Ai\AiDecider;
use App\Services\Ai\AnalysisRunner;
use App\Services\Ai\ClipClassifier;
use App\Services\Ai\QuoteVerifier;
use App\Services\Ai\RecommendationEvaluator;
use App\Services\Ai\ReviewJudgmentRunner;
use App\Services\Ai\ReviewSplitter;
use App\Services\Ai\VerdictConsensus;
use App\Services\MaterialFreshness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Tests\TestCase;

class ReviewSplitTest extends TestCase
{
    use RefreshDatabase;

    private function makeReviewSource(Company $company): SourceDocument
    {
        return $company->sourceDocuments()->create([
            'type' => SourceType::Review,
            'origin' => SourceOrigin::Paste,
            'url' => 'https://example.com/reviews',
            'raw_text' => '口コミページ本文',
        ]);
    }

    /**
     * @param  array<int, array<string, string>>  ...$batches  呼び出し毎に順番に返す分割結果
     */
    private function mockSplitter(array ...$batches): void
    {
        $mock = Mockery::mock(ReviewSplitter::class);
        $mock->shouldReceive('split')->andReturn(...$batches);
        $this->app->instance(ReviewSplitter::class, $mock);
    }

    private function mockClassifier(string $sourceType): void
    {
        $mock = Mockery::mock(ClipClassifier::class);
        $mock->shouldReceive('classify')->andReturn([
            'is_company_related' => true,
            'company_name' => '株式会社テスト',
            'source_type' => $sourceType,
            'summary' => 'テスト',
        ]);
        $this->app->instance(ClipClassifier::class, $mock);
    }

    public function test_口コミクリップで分割ジョブが投入される(): void
    {
        Queue::fake();
        $this->mockClassifier('review');

        $result = $this->app->make(IngestClipAction::class)
            ->execute('タイトル', 'https://example.com/r', '口コミ本文テキスト');

        $this->assertTrue($result['ok']);
        Queue::assertPushed(SplitReviewSourceJob::class);
    }

    public function test_求人票クリップでは分割ジョブを投入しない(): void
    {
        Queue::fake();
        $this->mockClassifier('jobposting');

        $result = $this->app->make(IngestClipAction::class)
            ->execute('タイトル', 'https://example.com/j', '求人票テキスト');

        $this->assertTrue($result['ok']);
        Queue::assertNotPushed(SplitReviewSourceJob::class);
    }

    public function test_手動投入でも口コミなら分割ジョブが投入される(): void
    {
        Queue::fake();
        $company = Company::create(['name' => '株式会社テスト']);

        $this->post(route('sources.store', $company), [
            'type' => 'review',
            'origin' => 'paste',
            'raw_text' => '口コミ本文',
        ])->assertRedirect();

        Queue::assertPushed(SplitReviewSourceJob::class);
    }

    public function test_分割で口コミが1件ずつ保存され空白違いの重複は統合される(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $source = $this->makeReviewSource($company);

        $this->mockSplitter([
            [
                'body' => '残業が多い',
                'stance' => '退職',
                'posted_period' => '回答日 2023年5月',
                'tenure_period' => '2019年〜2023年',
                'category' => 'ワークライフバランス',
                'job_type' => 'エンジニア',
            ],
            [
                'body' => '残業　が 多い',
                'stance' => '不明',
                'posted_period' => '',
                'tenure_period' => '',
                'category' => '',
                'job_type' => '',
            ],
            [
                'body' => '教育制度が手厚い',
                'stance' => '現職',
                'posted_period' => '',
                'tenure_period' => '',
                'category' => '',
                'job_type' => '',
            ],
        ]);

        $this->app->make(SplitReviewSourceAction::class)->execute($source);

        $this->assertSame(2, ReviewItem::count(), '空白だけ違う同一口コミは1件に統合される');
        $this->assertNotNull($source->fresh()->split_at);

        $item = ReviewItem::where('body', '残業が多い')->firstOrFail();
        $this->assertSame('退職', $item->stance);
        $this->assertSame('回答日 2023年5月', $item->posted_period);
        $this->assertSame('2019年〜2023年', $item->tenure_period, '在籍時期は投稿時期と別カラムに入る');
        $this->assertSame('エンジニア', $item->job_type);

        $unknown = ReviewItem::where('body', '教育制度が手厚い')->firstOrFail();
        $this->assertNull($unknown->posted_period);
        $this->assertNull($unknown->tenure_period, '空文字は null で保存される');
        $this->assertNull($unknown->job_type);
    }

    public function test_再分割で同一ソースのアイテムが入れ替わる(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $source = $this->makeReviewSource($company);

        $this->mockSplitter(
            [[
                'body' => '古い口コミ',
                'stance' => '不明',
                'posted_period' => '',
                'tenure_period' => '',
                'category' => '',
                'job_type' => '',
            ]],
            [[
                'body' => '新しい口コミ',
                'stance' => '不明',
                'posted_period' => '',
                'tenure_period' => '',
                'category' => '',
                'job_type' => '',
            ]],
        );

        $action = $this->app->make(SplitReviewSourceAction::class);
        $action->execute($source);
        $action->execute($source->fresh());

        $this->assertSame(1, ReviewItem::count(), '再分割で古いアイテムは残らない');
        $this->assertSame('新しい口コミ', ReviewItem::firstOrFail()->body);
    }

    public function test_口コミ以外のソースは分割できない(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $source = $company->sourceDocuments()->create([
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '求人票テキスト',
        ]);

        $this->expectException(\LogicException::class);

        $this->app->make(SplitReviewSourceAction::class)->execute($source);
    }

    public function test_口コミ素材の有無は分割済みアイテムで判定する(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $source = $this->makeReviewSource($company);
        $runner = $this->app->make(AnalysisRunner::class);

        $this->assertFalse(
            $runner->hasMaterialFor($company, AnalysisStep::P4),
            '口コミソースがあってもアイテム0件なら材料なし',
        );

        ReviewItem::create([
            'company_id' => $company->id,
            'source_document_id' => $source->id,
            'body' => '残業が多い',
            'body_hash' => sha1('残業が多い'),
        ]);

        $this->assertTrue($runner->hasMaterialFor($company, AnalysisStep::P4));
    }

    public function test_分析開始時に未分割の口コミソースを分割する(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $source = $this->makeReviewSource($company);
        $run = $company->analysisRuns()->create(['status' => RunStatus::Queued]);

        $splitAction = Mockery::mock(SplitReviewSourceAction::class);
        $splitAction->shouldReceive('execute')
            ->once()
            ->withArgs(fn (SourceDocument $doc): bool => $doc->id === $source->id);

        $runner = Mockery::mock(AnalysisRunner::class);
        $runner->shouldReceive('hasMaterialFor')->andReturn(false);
        // 素材なしでスキップする際、ジョブは理由文を runner から取る
        $runner->shouldReceive('skipReasonFor')->andReturn('素材未投入');
        $runner->shouldNotReceive('runStep');

        (new AnalyzeCompanyJob($run->id))->handle(
            $runner,
            $this->app->make(RecommendationEvaluator::class),
            $splitAction,
            $this->app->make(QuoteVerifier::class),
            $this->app->make(VerdictConsensus::class),
            $this->app->make(MaterialFreshness::class),
            $this->app->make(AiDecider::class),
            $this->app->make(ReviewJudgmentRunner::class),
        );

        $this->assertSame(RunStatus::Done, $run->fresh()->status);
    }

    public function test_ソース詳細で投稿時期と在籍時期と由来サイトが別々に渡る(): void
    {
        Carbon::setTestNow('2026-07-30');

        $company = Company::create(['name' => '株式会社テスト']);
        $source = $company->sourceDocuments()->create([
            'type' => SourceType::Review,
            'origin' => SourceOrigin::Url,
            'url' => 'https://openwork.jp/company/1',
            'raw_text' => '口コミページ本文',
            'split_at' => now(),
        ]);
        ReviewItem::create([
            'company_id' => $company->id,
            'source_document_id' => $source->id,
            'body' => '残業が多い',
            'stance' => '退職',
            'posted_period' => '回答日 2026年01月',
            'tenure_period' => '2010年代前半',
            'category' => 'ワークライフバランス',
            'job_type' => 'エンジニア',
            'body_hash' => sha1('残業が多い'),
        ]);

        $this->get("/sources/{$source->id}")
            ->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('source.reviewItems.0.postedPeriod', '回答日 2026年01月')
                ->where('source.reviewItems.0.tenurePeriod', '2010年代前半')
                ->where('source.reviewItems.0.jobType', 'エンジニア')
                ->where('source.reviewItems.0.sourceHost', 'openwork.jp')
                ->where('source.reviewItems.0.isRecent', true)
                ->where('source.reviewItems.0.periodLabel', '最近2年以内'));
    }

    public function test_ソース詳細の新旧判定は投稿時期が無ければ在籍時期を使う(): void
    {
        Carbon::setTestNow('2026-07-30');

        $company = Company::create(['name' => '株式会社テスト']);
        $source = $this->makeReviewSource($company);
        ReviewItem::create([
            'company_id' => $company->id,
            'source_document_id' => $source->id,
            'body' => '教育制度が手厚い',
            'tenure_period' => '2015年〜2025年在籍',
            'body_hash' => sha1('教育制度が手厚い'),
        ]);

        $this->get("/sources/{$source->id}")
            ->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('source.reviewItems.0.postedPeriod', null)
                ->where('source.reviewItems.0.isRecent', true)
                ->where('source.reviewItems.0.sourceHost', 'example.com'));
    }

    public function test_再分割コマンドのdry_runは件数だけ表示して何も変更しない(): void
    {
        Queue::fake();
        $company = Company::create(['name' => '株式会社テスト']);
        $source = $this->makeReviewSource($company);
        $source->update(['split_at' => now()]);

        $this->artisan('karte:resplit-reviews', ['--dry-run' => true])
            ->expectsOutputToContain('対象の口コミソース: 1件')
            ->expectsOutputToContain('--dry-run のため何も変更していません。')
            ->assertExitCode(0);

        $this->assertNotNull($source->fresh()->split_at, 'dry-run では split_at を消さない');
        Queue::assertNotPushed(SplitReviewSourceJob::class);
    }

    public function test_再分割コマンドはsplit_atを消して分割ジョブを投入する(): void
    {
        Queue::fake();
        $company = Company::create(['name' => '株式会社テスト']);
        $source = $this->makeReviewSource($company);
        $source->update(['split_at' => now()]);

        // 求人票は再分割の対象外
        $company->sourceDocuments()->create([
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '求人票テキスト',
        ]);

        $this->artisan('karte:resplit-reviews', ['--force' => true])->assertExitCode(0);

        $this->assertNull($source->fresh()->split_at);
        Queue::assertPushed(SplitReviewSourceJob::class, 1);
    }

    public function test_再分割コマンドは確認を拒否すると何もしない(): void
    {
        Queue::fake();
        $company = Company::create(['name' => '株式会社テスト']);
        $source = $this->makeReviewSource($company);
        $source->update(['split_at' => now()]);

        $this->artisan('karte:resplit-reviews')
            ->expectsConfirmation('1件を再分割しますか？（既存の分割結果は作り直されます）', 'no')
            ->assertExitCode(1);

        $this->assertNotNull($source->fresh()->split_at);
        Queue::assertNotPushed(SplitReviewSourceJob::class);
    }
}
