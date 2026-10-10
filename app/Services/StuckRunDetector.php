<?php

namespace App\Services;

use App\Models\AnalysisRun;
use Illuminate\Support\Carbon;

/**
 * 実行中（queued / running）のまま更新が途絶えた分析ランの検知。
 *
 * ワーカーが強制終了・クラッシュすると failed() が走らず、ランは Running のまま残る。
 * その状態では再分析も再開も企業削除も拒否され、手で DB を直すしかなくなるため、
 * 「最後の更新から retry_after 秒以上経った」ランを応答なしと判定して中断を許す。
 *
 * retry_after を閾値にする理由: ジョブの締め切りは QueueHeadroom により retry_after より
 * 短いことが保証されるので、その時間まったく更新が無ければジョブは生きていない。
 */
class StuckRunDetector
{
    public function __construct(private readonly QueueHeadroom $headroom) {}

    public function thresholdSeconds(): int
    {
        return $this->headroom->retryAfter();
    }

    /** 最後に何か書き込まれた時刻（ラン本体・ステップ・判定者の生出力のうち最新） */
    public function lastActivityAt(AnalysisRun $run): ?Carbon
    {
        $candidates = array_filter([
            $run->updated_at,
            $run->started_at,
            $run->steps()->max('updated_at'),
            $run->opinions()->max('updated_at'),
        ]);

        if ($candidates === []) {
            return null;
        }

        return collect($candidates)->map(fn ($t) => Carbon::parse($t))->max();
    }

    public function isStuck(AnalysisRun $run): bool
    {
        if ($run->status->isTerminal()) {
            return false;
        }

        $last = $this->lastActivityAt($run);

        return $last === null || $last->lte(now()->subSeconds($this->thresholdSeconds()));
    }
}
