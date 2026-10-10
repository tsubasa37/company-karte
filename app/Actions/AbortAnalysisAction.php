<?php

namespace App\Actions;

use App\Enums\RunStatus;
use App\Enums\StepStatus;
use App\Exceptions\AnalysisBlockedException;
use App\Models\AnalysisRun;
use App\Services\StuckRunDetector;
use Illuminate\Support\Facades\DB;

/**
 * 応答が途絶えた分析ランを手動で中断し、失敗として確定する（続きからの再開を可能にする）。
 *
 * 動いている可能性があるランは中断しない（StuckRunDetector の判定が正）。
 * キューに残った同じランのジョブ行も消す。残すとワーカー再起動後に再試行され、
 * tries=1 超過の failed() が再開後のランを失敗に上書きするため。
 */
class AbortAnalysisAction
{
    public const REASON = '応答が無いため手動で中断しました（失敗したステップから再開できます）';

    public function __construct(private readonly StuckRunDetector $detector) {}

    public function execute(AnalysisRun $run): void
    {
        if (! $this->detector->isStuck($run)) {
            $minutes = (int) ceil($this->detector->thresholdSeconds() / 60);

            throw new AnalysisBlockedException(
                "このランはまだ動いている可能性があるため中断できません（最後の更新から{$minutes}分以上応答が無いときだけ中断できます）。"
            );
        }

        DB::transaction(function () use ($run) {
            $this->deleteQueuedJobs($run);

            $run->steps()
                ->where('status', StepStatus::Running->value)
                ->update(['status' => StepStatus::Failed->value, 'error' => self::REASON]);

            $run->update([
                'status' => RunStatus::Failed,
                'current_step' => null,
                'error' => self::REASON,
                'finished_at' => now(),
            ]);
        });
    }

    /**
     * jobs テーブルに残っている、このランの AnalyzeCompanyJob を消す。
     * payload はシリアライズ済みジョブを含む JSON で、ラン ID は `\"analysisRunId\";i:<id>;` の形で入る。
     */
    private function deleteQueuedJobs(AnalysisRun $run): int
    {
        return DB::table('jobs')
            ->where('payload', 'like', '%AnalyzeCompanyJob%')
            ->where('payload', 'like', '%analysisRunId\\";i:'.$run->id.';%')
            ->delete();
    }
}
