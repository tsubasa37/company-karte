<?php

namespace Tests\Feature;

use App\Enums\AnalysisStep;
use App\Enums\RunStatus;
use App\Enums\StepStatus;
use App\Jobs\AnalyzeCompanyJob;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * timeout 等で handle 内の catch を経ずにジョブが失敗したときの状態復旧（AnalyzeCompanyJob::failed）。
 * ここが壊れるとランが Running のまま固着し、再分析・再開・企業削除の全部が拒否される。
 */
class AnalysisJobFailedHookTest extends TestCase
{
    use RefreshDatabase;

    public function test_実行中のランとステップを失敗として確定する(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $run = $company->analysisRuns()->create(['status' => RunStatus::Running, 'started_at' => now()]);
        $run->steps()->create(['step' => AnalysisStep::P1->value, 'status' => StepStatus::Done]);
        $run->steps()->create(['step' => AnalysisStep::P2->value, 'status' => StepStatus::Running]);
        $run->steps()->create(['step' => AnalysisStep::P3->value, 'status' => StepStatus::Pending]);

        (new AnalyzeCompanyJob($run->id))->failed(new \RuntimeException('killed by timeout'));

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('killed by timeout', $run->error);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(StepStatus::Failed, $run->stepFor(AnalysisStep::P2)->status, '実行中だったステップは失敗');
        $this->assertSame(StepStatus::Done, $run->stepFor(AnalysisStep::P1)->status, '完了済みは残る');
        $this->assertSame(StepStatus::Pending, $run->stepFor(AnalysisStep::P3)->status, '未着手は残る（続きから再開の前提）');
    }

    public function test_handle内で既に失敗確定済みなら二重に書き換えない(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $run = $company->analysisRuns()->create([
            'status' => RunStatus::Failed,
            'error' => 'handle 内で確定した理由',
            'finished_at' => now(),
        ]);

        (new AnalyzeCompanyJob($run->id))->failed(new \RuntimeException('後から来た理由'));

        $this->assertSame('handle 内で確定した理由', $run->fresh()->error);
    }
}
