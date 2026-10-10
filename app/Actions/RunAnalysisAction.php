<?php

namespace App\Actions;

use App\Enums\AnalysisStep;
use App\Enums\RunStatus;
use App\Enums\StepStatus;
use App\Exceptions\AnalysisBlockedException;
use App\Jobs\AnalyzeCompanyJob;
use App\Models\AnalysisRun;
use App\Models\Company;
use App\Services\Ai\AiSettings;
use Illuminate\Support\Facades\DB;

/**
 * 企業の分析ランを1件作成し（step行を pending で用意）、分析ジョブをキューに投入する。
 * 投入前に DB をバックアップする（スケジューラを常駐させない方針のため、
 * 何十時間分のリサーチが入った単一 SQLite ファイルの復元点をここで作る）。
 */
class RunAnalysisAction
{
    public function __construct(
        private readonly BackupBeforeAnalysisAction $backup,
        private readonly AiSettings $aiSettings,
    ) {}

    /**
     * @param  int  $panelSize  P2（裏取り）を何人の判定者で行うか。ランに保存し、ジョブの
     *                          締め切り計算と判定者の観点の割り当てに使う
     */
    public function execute(Company $company, int $panelSize): AnalysisRun
    {
        // VACUUM INTO はトランザクション内で実行できないため、ロックを取る前に済ませる
        $this->backup->execute();

        // 同時に2回押されたときの二重投入は、書き込みロック（IMMEDIATE）下で再確認して防ぐ。
        // 画面側のガード（AnalysisGuard）はロック外の判定なので、ここが最後の砦
        return DB::transaction(function () use ($company, $panelSize) {
            if ($company->analysisRuns()->unfinished()->exists()) {
                throw new AnalysisBlockedException('分析が既に実行中です。完了までお待ちください。');
            }

            $run = $company->analysisRuns()->create([
                'status' => RunStatus::Queued,
                'panel_size' => $panelSize,
            ]);

            foreach (AnalysisStep::pipeline() as $step) {
                // 判定エンジンがオフなら RJ の待機行を作らない（進捗に出さない。ジョブがスキップとして記録する）
                if ($step === AnalysisStep::RJ && ! $this->aiSettings->isDecisionConfigured()) {
                    continue;
                }

                $run->steps()->create([
                    'step' => $step->value,
                    'status' => StepStatus::Pending,
                ]);
            }

            AnalyzeCompanyJob::dispatch($run->id);

            return $run;
        });
    }
}
