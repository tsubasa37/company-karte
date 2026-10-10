<?php

namespace App\Jobs;

use App\Actions\SplitReviewSourceAction;
use App\Jobs\Concerns\AssertsQueueHeadroom;
use App\Models\SourceDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * クリップ/手動投入の応答を待たせないよう、口コミ分割は非同期で行う。
 * 失敗は握り潰さず failed_jobs に残す。未分割のまま残っても、
 * 分析開始時（AnalyzeCompanyJob）に同期分割されるため取りこぼさない。
 */
class SplitReviewSourceJob implements ShouldQueue
{
    use AssertsQueueHeadroom;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $sourceDocumentId)
    {
        $this->assertQueueHeadroom();
    }

    public function handle(SplitReviewSourceAction $action): void
    {
        $source = SourceDocument::find($this->sourceDocumentId);

        // 分割前にソースが削除された場合、分割対象自体が無い（失敗ではない）
        if ($source === null) {
            return;
        }

        $action->execute($source);
    }

    /**
     * 分割は split_at が null のままになるため、次の分析実行時に AnalyzeCompanyJob が
     * 同期で分割し直す（状態のリセットは不要）。ここでは失敗を記録として残す。
     */
    public function failed(\Throwable $e): void
    {
        Log::error('口コミの分割に失敗しました', [
            'source_document_id' => $this->sourceDocumentId,
            'message' => $e->getMessage(),
        ]);
    }
}
