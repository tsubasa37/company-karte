<?php

namespace Tests\Feature;

use App\Actions\SplitReviewSourceAction;
use App\Enums\AnalysisStep;
use App\Enums\RunStatus;
use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Enums\StepStatus;
use App\Jobs\AnalyzeCompanyJob;
use App\Jobs\GenerateQuestionsJob;
use App\Models\Company;
use App\Services\Ai\AiDecider;
use App\Services\Ai\AiRunner;
use App\Services\Ai\AnalysisRunner;
use App\Services\Ai\QuoteVerifier;
use App\Services\Ai\RecommendationEvaluator;
use App\Services\Ai\ReviewJudgmentRunner;
use App\Services\Ai\VerdictConsensus;
use App\Services\MaterialFreshness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * 分析ジョブの失敗経路。codex 失敗が analysis_steps.error / analysis_runs.error に
 * 残ること（サイレント失敗禁止の実体）を固定する。
 */
class AnalysisFailureTest extends TestCase
{
    use RefreshDatabase;

    private function companyWithRun(): Company
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $company->sourceDocuments()->create([
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '求人票本文',
        ]);
        $run = $company->analysisRuns()->create(['status' => RunStatus::Queued]);
        foreach (AnalysisStep::pipeline() as $step) {
            $run->steps()->create(['step' => $step->value, 'status' => StepStatus::Pending]);
        }

        return $company;
    }

    public function test_ステップ失敗はエラーを残してランを中断する(): void
    {
        $company = $this->companyWithRun();
        $run = $company->analysisRuns()->firstOrFail();

        $codex = Mockery::mock(AiRunner::class);
        $codex->shouldReceive('runWithSchema')->once()->andThrow(new \RuntimeException('Codex failed: timeout'));
        $this->app->instance(AiRunner::class, $codex);

        try {
            (new AnalyzeCompanyJob($run->id))->handle(
                $this->app->make(AnalysisRunner::class),
                $this->app->make(RecommendationEvaluator::class),
                $this->app->make(SplitReviewSourceAction::class),
                $this->app->make(QuoteVerifier::class),
                $this->app->make(VerdictConsensus::class),
                $this->app->make(MaterialFreshness::class),
                $this->app->make(AiDecider::class),
                $this->app->make(ReviewJudgmentRunner::class),
            );
            $this->fail('例外が再送出されるはず（握り潰し禁止）');
        } catch (\RuntimeException) {
        }

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('Codex failed', (string) $run->error);
        $p1 = $run->stepFor(AnalysisStep::P1);
        $this->assertSame(StepStatus::Failed, $p1->status);
        $this->assertStringContainsString('Codex failed', (string) $p1->error);
        // 失敗より後のステップは実行されず pending のまま（続きから再開の前提）
        $this->assertSame(StepStatus::Pending, $run->stepFor(AnalysisStep::P2)->status);
    }

    public function test_逆質問ジョブの失敗はp6ステップにエラーを残す(): void
    {
        $company = $this->companyWithRun();
        $run = $company->analysisRuns()->firstOrFail();
        $run->update(['status' => RunStatus::Done, 'finished_at' => now()]);
        $run->stepFor(AnalysisStep::P2)->update([
            'status' => StepStatus::Done,
            'output_json' => ['verdicts' => [[
                'claim' => 'c', 'source' => '', 'checked_against' => '', 'verdict' => 'unverifiable', 'rationale' => '',
            ]]],
        ]);

        $job = new GenerateQuestionsJob($run->id);
        $job->failed(new \RuntimeException('codex timed out'));

        // 事前に p6 行が無くても failed() で行ごと確定する必要は無い（Pending作成後の失敗を再現）
        $run->steps()->updateOrCreate(
            ['step' => AnalysisStep::P6->value],
            ['status' => StepStatus::Pending],
        );
        $job->failed(new \RuntimeException('codex timed out'));

        $p6 = $run->fresh()->stepFor(AnalysisStep::P6);
        $this->assertSame(StepStatus::Failed, $p6->status);
        $this->assertSame('codex timed out', $p6->error);
    }
}
