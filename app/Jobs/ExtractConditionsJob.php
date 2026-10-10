<?php

namespace App\Jobs;

use App\Enums\SourceType;
use App\Jobs\Concerns\AssertsQueueHeadroom;
use App\Models\Company;
use App\Services\Ai\AnalysisRunner;
use App\Services\Ai\ConditionExtractor;
use App\Services\Ai\SourceMaterialText;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 求人票ソースの投入・削除のたびに、企業の労働条件（conditions_json）を抽出し直す。
 * 求人票が無くなった場合は条件も消す（古い抽出結果を残さない）。失敗は failed_jobs に残る。
 */
class ExtractConditionsJob implements ShouldQueue
{
    use AssertsQueueHeadroom;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $companyId)
    {
        $this->assertQueueHeadroom();
    }

    public function handle(ConditionExtractor $extractor, SourceMaterialText $material): void
    {
        $company = Company::find($this->companyId);

        // 抽出前に企業が削除された場合、対象自体が無い（失敗ではない）
        if ($company === null) {
            return;
        }

        $text = $company->sourceDocuments()
            ->whereIn('type', [SourceType::JobPosting->value, SourceType::Other->value])
            ->get()
            ->map(fn ($d) => mb_substr($material->of($d), 0, AnalysisRunner::MAX_SOURCE_CHARS))
            ->implode("\n\n");

        if (trim($text) === '') {
            $company->update(['conditions_json' => null]);

            return;
        }

        $company->update(['conditions_json' => $extractor->extract($text, $company->id)]);
    }

    /**
     * 抽出に失敗したら条件を空にする。古い抽出結果を残すと、比較ビューで
     * 最新の求人票の条件であるかのように表示されてしまうため（失敗の詳細は failed_jobs に残る）。
     */
    public function failed(\Throwable $e): void
    {
        Company::find($this->companyId)?->update(['conditions_json' => null]);
    }
}
