<?php

namespace App\Actions;

use App\Enums\RunStatus;
use App\Enums\StepStatus;
use App\Exceptions\AnalysisBlockedException;
use App\Jobs\AnalyzeCompanyJob;
use App\Models\AnalysisRun;
use App\Services\AnalysisTimeoutEstimator;
use App\Services\QueueHeadroom;
use Illuminate\Support\Facades\DB;

/**
 * 失敗した分析ランを「続きから再開」する。完了済みステップは活かし、
 * 失敗ステップを pending に戻して再ディスパッチ（Job が done ステップをスキップする）。
 * 再開も分析実行なので、新規実行と同じく復元点（バックアップ）と
 * 「同じ企業の未完了ランが無いこと」を必須にする（素通りさせない）。
 */
class RetryAnalysisAction
{
    public function __construct(
        private readonly BackupBeforeAnalysisAction $backup,
        private readonly AnalysisTimeoutEstimator $estimator,
        private readonly QueueHeadroom $headroom,
    ) {}

    public function execute(AnalysisRun $run): void
    {
        if ($run->status !== RunStatus::Failed) {
            throw new AnalysisBlockedException('失敗したランのみ続きから再開できます。');
        }

        // 新しい分析が待機中・実行中のまま古い失敗ランを再開すると、同じ企業の分析が
        // 2件走り AI を二重に消費する。バックアップを取る前に理由を出して止める
        $this->assertNoUnfinishedRun($run);

        // 二重実行（AI の二重消費）の原因になる締め切り超過は、状態を変える前に止める
        $shortfall = $this->headroom->shortfall($this->estimator->forRun($run), '分析ジョブ');
        if ($shortfall !== null) {
            throw new AnalysisBlockedException($shortfall);
        }

        $this->backup->execute();

        DB::transaction(function () use ($run) {
            // 押した後に別の分析が投入されている場合がある。書き込みロック下で最後に確認する
            $this->assertNoUnfinishedRun($run);

            $run->steps()
                ->where('status', StepStatus::Failed->value)
                ->update(['status' => StepStatus::Pending->value, 'error' => null]);

            $run->update(['status' => RunStatus::Queued, 'error' => null]);

            AnalyzeCompanyJob::dispatch($run->id);
        });
    }

    /** 同じ企業に未完了（待機中・分析中）のランがあれば再開させない */
    private function assertNoUnfinishedRun(AnalysisRun $run): void
    {
        $unfinished = AnalysisRun::where('company_id', $run->company_id)
            ->unfinished()
            ->exists();

        if ($unfinished) {
            throw new AnalysisBlockedException(
                'この企業では別の分析が実行中です。完了してから再開してください。'
            );
        }
    }
}
