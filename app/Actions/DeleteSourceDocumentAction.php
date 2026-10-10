<?php

namespace App\Actions;

use App\Enums\SourceType;
use App\Jobs\ExtractConditionsJob;
use App\Jobs\SplitReviewSourceJob;
use App\Models\SourceDocument;

/**
 * ソースを削除し、そのソースから派生していたデータ（労働条件・口コミ分割）を作り直す。
 */
class DeleteSourceDocumentAction
{
    /** @return int 削除元の企業ID（呼び出し側のリダイレクト先に使う） */
    public function execute(SourceDocument $source): int
    {
        $company = $source->company;
        $type = $source->type;

        $source->delete();

        // 求人票を消したら労働条件も抽出し直す（求人票ゼロなら条件は消える）
        if (in_array($type, [SourceType::JobPosting, SourceType::Other], true)) {
            ExtractConditionsJob::dispatch($company->id);
        }

        // 複数ページに重複掲載された口コミは「最初に分割したソース」にだけ紐づくため、
        // そのソースを消すと cascade で消える（他ソースに本文が残っていても失われる）。
        // 残りの口コミソースを分割し直して復元する。
        if ($type === SourceType::Review) {
            $remaining = $company->sourceDocuments()
                ->where('type', SourceType::Review->value)
                ->pluck('id');

            $company->sourceDocuments()
                ->where('type', SourceType::Review->value)
                ->update(['split_at' => null]);

            foreach ($remaining as $id) {
                SplitReviewSourceJob::dispatch($id);
            }
        }

        return $company->id;
    }
}
