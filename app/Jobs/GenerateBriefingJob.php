<?php

namespace App\Jobs;

use App\Enums\RunStatus;
use App\Jobs\Concerns\AssertsQueueHeadroom;
use App\Models\Company;
use App\Services\Ai\BriefingGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 面接前ブリーフィングを非同期生成する。失敗は briefing_error に残し、
 * 生成中フラグ（briefing_requested_at）を解除する（サイレント失敗禁止）。
 */
class GenerateBriefingJob implements ShouldQueue
{
    use AssertsQueueHeadroom;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $companyId)
    {
        $this->assertQueueHeadroom();
    }

    public function handle(BriefingGenerator $generator): void
    {
        $company = Company::findOrFail($this->companyId);

        $run = $company->analysisRuns()
            ->where('status', RunStatus::Done)
            ->latest('id')
            ->first();

        if ($run === null) {
            throw new \LogicException("完了済みの分析が無いためブリーフィングを生成できません: company={$company->id}");
        }

        $output = $generator->generate($run);

        // requested_at は完了・失敗の両方で null に戻す（= 立っている間だけが「生成中」）。
        // EDINET 取得と同じ状態遷移に揃えている。
        $company->update([
            'briefing_json' => $output,
            'briefing_generated_at' => now(),
            'briefing_error' => null,
            'briefing_requested_at' => null,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Company::find($this->companyId)?->update([
            'briefing_requested_at' => null,
            'briefing_error' => $e->getMessage(),
        ]);
    }
}
