<?php

namespace Tests\Unit;

use App\Enums\AiPurpose;
use App\Enums\AiTier;
use App\Enums\AnalysisStep;
use App\Enums\RunStatus;
use App\Models\AnalysisRun;
use App\Models\Company;
use App\Services\Ai\AiDecider;
use App\Services\Ai\AiRunner;
use App\Services\Ai\AnalysisRunner;
use App\Services\Ai\BriefingGenerator;
use App\Services\Ai\ClipClassifier;
use App\Services\Ai\CompanyListExtractor;
use App\Services\Ai\ConditionExtractor;
use App\Services\Ai\MailExtractor;
use App\Services\Ai\ProfileText;
use App\Services\Ai\ReviewSplitter;
use App\Services\Ai\SourceMaterialText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * 呼び出し元 7 サービスがどの Tier（用途の重さ）と用途で AI を呼ぶかを固定する。
 * 抽出・判定はメール取得や保存に付随して自動実行されるため、分析・要約 Tier に取り違えると
 * 「押していないのに重い実行先で走る」ことになり、実行先を Tier ごとに分けた意味が無くなる。
 */
class AiTierAssignmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 期待する Tier・用途で1回だけ呼ばれることを検証するモック。
     *
     * @param  array<string, mixed>  $result
     */
    private function runnerExpecting(AiTier $tier, AiPurpose $purpose, array $result): AiRunner
    {
        $ai = Mockery::mock(AiRunner::class);
        $ai->shouldReceive('runWithSchema')
            ->once()
            ->withArgs(fn (array $schema, string $prompt, AiTier $actualTier, ?AiPurpose $actualPurpose) => $actualTier === $tier && $actualPurpose === $purpose)
            ->andReturn($result);

        return $ai;
    }

    public function test_分析ステップは分析要約tierで実行する(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $this->app->instance(AiRunner::class, $this->runnerExpecting(AiTier::Analysis, AiPurpose::Analysis, ['claims' => []]));

        $this->app->make(AnalysisRunner::class)->runStep($company, AnalysisStep::P1, []);
    }

    public function test_逆質問の個別生成も分析要約tierで実行する(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $this->app->instance(AiRunner::class, $this->runnerExpecting(AiTier::Analysis, AiPurpose::Questions, ['questions' => []]));

        $this->app->make(AnalysisRunner::class)->runStep($company, AnalysisStep::P6, []);
    }

    public function test_面接前ブリーフィングは分析要約tierで実行する(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $run = AnalysisRun::create(['company_id' => $company->id, 'status' => RunStatus::Done]);
        $ai = $this->runnerExpecting(AiTier::Analysis, AiPurpose::Briefing, [
            'summary_lines' => [],
            'open_issues' => [],
            'top_questions' => [],
            'weak_points' => [],
            'note' => '',
        ]);

        (new BriefingGenerator($ai, $this->app->make(ProfileText::class), $this->app->make(SourceMaterialText::class)))->generate($run);
    }

    public function test_クリップの企業種別判定は抽出判定tierで実行する(): void
    {
        $ai = $this->runnerExpecting(AiTier::Light, AiPurpose::ClipClassify, [
            'is_company_related' => false,
            'is_multi_company' => false,
            'company_name' => '',
            'source_type' => 'other',
            'summary' => '',
        ]);

        (new ClipClassifier($ai, $this->app->make(AiDecider::class)))->classify('タイトル', 'https://example.com/', 'ページ本文');
    }

    public function test_労働条件の抽出は抽出判定tierで実行する(): void
    {
        $ai = $this->runnerExpecting(AiTier::Light, AiPurpose::ConditionExtract, []);

        (new ConditionExtractor($ai))->extract('月給30万円');
    }

    public function test_口コミの分割は抽出判定tierで実行する(): void
    {
        $ai = $this->runnerExpecting(AiTier::Light, AiPurpose::ReviewSplit, ['reviews' => []]);

        (new ReviewSplitter($ai))->split('口コミページの本文');
    }

    public function test_いいね一覧からの企業抽出は抽出判定tierで実行する(): void
    {
        $ai = $this->runnerExpecting(AiTier::Light, AiPurpose::ClipList, ['companies' => []]);

        (new CompanyListExtractor($ai))->extract('お気に入り一覧', 'https://example.com/favorites', '株式会社A 株式会社B');
    }

    public function test_メールの抽出は抽出判定tierで実行する(): void
    {
        $ai = $this->runnerExpecting(AiTier::Light, AiPurpose::MailExtract, [
            'is_job_related' => false,
            'company_name' => '',
            'mail_kind' => 'other',
            'position' => '',
            'event_candidates' => [],
            'summary' => '',
        ]);

        (new MailExtractor($ai))->extract('件名', '本文', '2026-08-20 09:00');
    }
}
