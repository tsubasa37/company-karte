<?php

namespace Tests\Unit;

use App\Enums\AiProvider;
use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Models\AiSetting;
use App\Models\Company;
use App\Models\ReviewItem;
use App\Services\AnalysisTimeoutEstimator;
use App\Services\QueueHeadroom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 分析ジョブの締め切りの見積もりに、口コミの機械判定（RJ）の分が載ること。
 * RJ は分析 Tier の上限秒では数えず、判定エンジン設定済みのときだけ「区切りの数 × 判定の上限秒」を足す。
 */
class AnalysisTimeoutEstimatorTest extends TestCase
{
    use RefreshDatabase;

    /** RJ を除いた自動実行のステップ数（P1〜P5） */
    private const GENERATION_STEPS = 5;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.codex.timeout' => 180,
            'ai.providers.jev.timeout' => 10,
            'ai.decision.concurrency' => 20,
        ]);
    }

    private function companyWithReviews(int $count): Company
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $doc = $company->sourceDocuments()->create([
            'type' => SourceType::Review,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '口コミ',
            'split_at' => now(),
        ]);
        for ($i = 0; $i < $count; $i++) {
            ReviewItem::create([
                'company_id' => $company->id,
                'source_document_id' => $doc->id,
                'body' => "口コミ{$i}",
                'body_hash' => sha1("口コミ{$i}"),
            ]);
        }

        return $company;
    }

    public function test_判定エンジン未設定なら口コミがあっても従来と同じ見積もり(): void
    {
        $estimator = app(AnalysisTimeoutEstimator::class);

        $this->assertSame(180 * self::GENERATION_STEPS + 120, $estimator->forCompany($this->companyWithReviews(200), 1));
        $this->assertSame(180 * self::GENERATION_STEPS + 120, $estimator->forPanel(1));
    }

    public function test_判定エンジン設定済みなら口コミ200件で100秒を足す(): void
    {
        AiSetting::sole()->update(['decision_provider' => AiProvider::Jev]);

        // 200 件 / 同時数 20 = 10 区切り × 10 秒
        $this->assertSame(
            180 * self::GENERATION_STEPS + 120 + 100,
            app(AnalysisTimeoutEstimator::class)->forCompany($this->companyWithReviews(200), 1),
        );
    }

    public function test_端数の区切りも1回ぶん数える(): void
    {
        AiSetting::sole()->update(['decision_provider' => AiProvider::Jev]);

        $this->assertSame(
            180 * self::GENERATION_STEPS + 120 + 20,
            app(AnalysisTimeoutEstimator::class)->forCompany($this->companyWithReviews(21), 1),
        );
    }

    public function test_口コミが無ければ判定の分は足さない(): void
    {
        AiSetting::sole()->update(['decision_provider' => AiProvider::Jev]);

        $this->assertSame(180 * self::GENERATION_STEPS + 120, app(AnalysisTimeoutEstimator::class)->forCompany($this->companyWithReviews(0), 1));
    }

    public function test_判定3人と口コミ200件でも再予約時間1600秒に収まる(): void
    {
        AiSetting::sole()->update(['decision_provider' => AiProvider::Jev]);
        config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 1600]);

        $seconds = app(AnalysisTimeoutEstimator::class)->forCompany($this->companyWithReviews(200), 3);

        // 180 × (5 + 2) + 120 + 100
        $this->assertSame(1480, $seconds);
        $this->assertNull(app(QueueHeadroom::class)->shortfall($seconds, '分析ジョブ'));
    }

    public function test_同時数の設定が無ければ見積もりで止める(): void
    {
        AiSetting::sole()->update(['decision_provider' => AiProvider::Jev]);
        config(['ai.decision.concurrency' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('decision.concurrency');

        app(AnalysisTimeoutEstimator::class)->forCompany($this->companyWithReviews(1), 1);
    }
}
