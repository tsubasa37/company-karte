<?php

namespace App\Actions;

use App\Enums\SourceType;
use App\Models\ReviewItem;
use App\Models\SourceDocument;
use App\Services\Ai\ReviewSplitter;
use App\Services\Ai\SourceMaterialText;

/**
 * 口コミソース1件を AI で「1口コミ = 1レコード」に分割して保存する。
 * 同じ口コミが複数ページに重複表示されても、正規化ハッシュで企業内1件に保つ。
 * 再分割時はこのソース由来の既存アイテムを入れ替える（再クリップでの内容更新に追随）。
 */
class SplitReviewSourceAction
{
    public function __construct(
        private readonly ReviewSplitter $splitter,
        private readonly SourceMaterialText $material,
    ) {}

    public function execute(SourceDocument $source): void
    {
        if ($source->type !== SourceType::Review) {
            throw new \LogicException(
                "口コミ以外のソースは分割できません: id={$source->id} type={$source->type->value}"
            );
        }

        $reviews = $this->splitter->split($this->material->of($source), $source->company_id);

        $source->reviewItems()->delete();

        foreach ($reviews as $review) {
            // 空白・改行の違いだけの同一口コミを1件に寄せるための正規化ハッシュ
            $hash = sha1((string) preg_replace('/\s|　/u', '', $review['body']));

            // 別ソース（別ページ）に同じ口コミが表示されていた場合は既存を残す
            ReviewItem::firstOrCreate(
                ['company_id' => $source->company_id, 'body_hash' => $hash],
                [
                    'source_document_id' => $source->id,
                    'body' => $review['body'],
                    'stance' => $review['stance'],
                    // 投稿時期（投稿日・回答日）と在籍時期は別カラム。空文字は「読み取れなかった」なので null に寄せる
                    'posted_period' => $this->orNull($review['posted_period']),
                    'tenure_period' => $this->orNull($review['tenure_period']),
                    'category' => $this->orNull($review['category']),
                    'job_type' => $this->orNull($review['job_type']),
                ],
            );
        }

        $source->update(['split_at' => now()]);
    }

    /** AI が「読み取れなかった」を空文字で返すため、DB では null に統一する */
    private function orNull(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
