<?php

namespace App\Jobs;

use App\Actions\MatchOpenIssuesAction;
use App\Jobs\Concerns\AssertsQueueHeadroom;
use App\Models\SourceDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 面接メモの投入のたびに、追跡中の論点に答えが出たかを判定して状態を更新する。
 * 追跡中の論点が無ければ AI を呼ばない（面接メモを入れるだけで課金されないようにする）。
 * 失敗は failed_jobs に残る（論点の状態は変えない）。
 */
class MatchOpenIssuesJob implements ShouldQueue
{
    use AssertsQueueHeadroom;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $sourceDocumentId)
    {
        $this->assertQueueHeadroom();
    }

    public function handle(MatchOpenIssuesAction $action): void
    {
        $note = SourceDocument::with('company')->find($this->sourceDocumentId);

        // 判定前に面接メモが削除された場合、対象自体が無い（失敗ではない）
        if ($note === null || $note->company === null) {
            return;
        }

        $action->execute($note->company, $note);
    }
}
