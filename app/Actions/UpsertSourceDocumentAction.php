<?php

namespace App\Actions;

use App\Enums\SourceType;
use App\Jobs\ExtractConditionsJob;
use App\Jobs\MatchOpenIssuesJob;
use App\Jobs\SplitReviewSourceJob;
use App\Models\Company;
use App\Models\SourceDocument;
use App\Services\SourceRevisionRecorder;
use App\Services\SourceTextCleaner;
use App\Services\UrlNormalizer;

/**
 * ソースを登録（同一URL・同一種別なら上書き）し、派生処理を起動する共通経路。
 * 画面フォームからの投入とブラウザ拡張からのクリップの双方がここを通る。
 */
class UpsertSourceDocumentAction
{
    public function __construct(
        private readonly SourceRevisionRecorder $revisions,
        private readonly SourceTextCleaner $cleaner,
        private readonly UrlNormalizer $urls,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  type/origin/url/raw_text/fetched_at 等
     */
    public function execute(Company $company, array $attributes): SourceDocument
    {
        $type = $attributes['type'];

        // 追跡パラメータ違い・末尾スラッシュ違いで同じページが別レコードにならないよう、
        // 照合キーと保存値の両方を正規化後の値にする（保存だけ生URLだと次回の照合が外れる）。
        $url = ($attributes['url'] ?? null) !== null
            ? $this->urls->normalize((string) $attributes['url'])
            : null;
        $attributes['url'] = $url;

        // ナビ・フッター等の定型行を落としてから保存する（上限文字数を本文に使うため）。
        // 面接メモは本人が書いた一次情報で短い行がノイズ辞書に誤爆して消えるため種別で除外する
        // （origin では判定しない: 手貼り origin=paste の求人票・口コミにはナビ屑が混ざるため）。
        $removedLines = null;
        if ($type !== SourceType::Interview->value) {
            $cleaned = $this->cleaner->clean((string) ($attributes['raw_text'] ?? ''));
            $attributes['raw_text'] = $cleaned['text'];
            $removedLines = $cleaned['removedLines'];
        }

        $replacedExisting = false;
        if ($url === null) {
            // URL の無いソース（貼り付け）は同一性の判定ができないため常に新規
            $source = $company->sourceDocuments()->create($attributes);
        } else {
            // 同一企業に同一 URL かつ同一種別があれば上書き（再取得で重複させない）。
            // 種別が違えば別ソースとして扱う（同じURLを別種別で取り込むケースを潰さない）。
            // 本文が変わる場合は旧本文を履歴へ退避し、split_at はリセットして分割し直す。
            $existing = $company->sourceDocuments()->where('url', $url)->where('type', $type)->first();
            $replacedExisting = $existing !== null;
            $this->revisions->record($existing, (string) $attributes['raw_text']);

            $source = $company->sourceDocuments()->updateOrCreate(
                ['url' => $url, 'type' => $type],
                $attributes + ['split_at' => null],
            );
        }

        $this->recordRemovedLines($source, $removedLines);
        $this->dispatchDerivedJobs($source, $replacedExisting);

        return $source;
    }

    /**
     * 除去した定型行の件数を残す（何行落としたかをソース詳細で隠さないため）。
     * 面接メモはクリーニング対象外なので null（画面側は種別を見て「未記録」と区別する）。
     * fillable に載せず明示代入するのは、投入経路が渡す attributes に紛れて
     * 上書きされないようにするため（値の出どころはこのクリーニング結果だけ）。
     */
    private function recordRemovedLines(SourceDocument $source, ?int $removedLines): void
    {
        $source->removed_lines = $removedLines;
        $source->save();
    }

    /** 口コミは「1口コミ=1件」へ分割し直し、求人票系は労働条件を抽出し直し、面接メモは論点を答え合わせする（非同期） */
    private function dispatchDerivedJobs(SourceDocument $source, bool $replacedExisting): void
    {
        if ($source->type === SourceType::Review) {
            // 複数ページに重複掲載された口コミは「最初に分割したソース」にだけ紐づく。
            // 既存ソースの再取得・再クリップで本文からその口コミが消えると、分割し直しで
            // 自ソース分のアイテムごと消え、他ソースに本文が残っていても復元されない。
            // そのため既存の上書き時だけは、削除経路（DeleteSourceDocumentAction）と同じく
            // 企業の口コミソース全部を分割し直す。新規ソースは他に影響しないので自分だけ
            // （全ソース分割は口コミソース数ぶん codex を消費するため、必要時に限定する）。
            if (! $replacedExisting) {
                SplitReviewSourceJob::dispatch($source->id);
            } else {
                $ids = $source->company->sourceDocuments()
                    ->where('type', SourceType::Review->value)
                    ->pluck('id');

                $source->company->sourceDocuments()
                    ->where('type', SourceType::Review->value)
                    ->update(['split_at' => null]);

                foreach ($ids as $id) {
                    SplitReviewSourceJob::dispatch($id);
                }
            }
        }
        if (in_array($source->type, [SourceType::JobPosting, SourceType::Other], true)) {
            ExtractConditionsJob::dispatch($source->company_id);
        }
        // 面接メモは追跡中の論点の答え合わせに使う（追跡中の論点が無ければ AI を呼ばない）
        if ($source->type === SourceType::Interview) {
            MatchOpenIssuesJob::dispatch($source->id);
        }
    }
}
