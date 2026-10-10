<?php

namespace App\Actions;

use App\Enums\SourceType;
use App\Models\ReviewItem;
use App\Models\SourceDocument;
use App\Services\Ai\AnalysisRunner;
use App\Services\Ai\ReviewSplitter;
use App\Services\ReviewPeriod;
use App\Services\SourceDiffService;

/**
 * ソース詳細ページ（Sources/Show）に渡すデータを組み立てる。
 * 分析に渡る範囲と切り捨て範囲の切り分け・口コミ分割結果・再取り込み差分をここで確定させる。
 */
class BuildSourcePageAction
{
    public function __construct(
        private readonly SourceDiffService $diff,
        private readonly ReviewPeriod $period,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(SourceDocument $source): array
    {
        $source->load('company');
        $raw = (string) $source->raw_text;
        // 求人票は上限が広いなど種別で違うため、分析側と同じ判定を使う
        $limit = AnalysisRunner::limitFor($source->type);

        return [
            'source' => [
                'id' => $source->id,
                'typeLabel' => $source->type->label(),
                'origin' => $source->origin->value,
                // 由来の文言は Enum が確定する（画面側で分岐すると新しい由来が「貼り付け」に化ける）
                'originLabel' => $source->origin->label(),
                'url' => $source->url,
                'length' => mb_strlen($raw),
                'fetchedAt' => $source->fetched_at?->format('Y-m-d H:i'),
                'createdAt' => $source->created_at?->format('Y-m-d H:i'),
                'analyzedText' => mb_substr($raw, 0, $limit),
                'truncatedText' => mb_substr($raw, $limit),
                'splitAt' => $source->split_at?->format('Y-m-d H:i'),
                'reviewItems' => $this->reviewItems($source),
                'revisions' => $this->revisionDiffs($source),
            ],
            'company' => ['id' => $source->company->id, 'name' => $source->company->name],
            'analysisSourceLimit' => $limit,
            'reviewSplitLimit' => ReviewSplitter::MAX_CHARS,
        ];
    }

    /**
     * 再取り込みで本文が変わった履歴を、隣接バージョン間の「変わった行」として返す（新しい順）。
     *
     * @return array<int, array<string, mixed>>
     */
    private function revisionDiffs(SourceDocument $source): array
    {
        $revisions = $source->revisions()->orderBy('id')->get()->values();

        $result = [];
        foreach ($revisions as $i => $rev) {
            // 各履歴は「その次のバージョン」（次の履歴、無ければ現在の本文）と比較する
            $newer = $revisions->get($i + 1)?->raw_text ?? (string) $source->raw_text;
            $result[] = [
                'id' => $rev->id,
                'replacedAt' => $rev->replaced_at?->format('Y-m-d H:i'),
                'diff' => $this->diff->changedLines((string) $rev->raw_text, (string) $newer),
            ];
        }

        return array_reverse($result);
    }

    /**
     * 口コミソースの分割済みアイテム（口コミ以外は null = 表示対象外）。
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function reviewItems(SourceDocument $source): ?array
    {
        if ($source->type !== SourceType::Review) {
            return null;
        }

        $currentYear = (int) now()->format('Y');

        // sourceDocument は「どの口コミサイト由来か」の表示に使う（1クエリで先読み）
        $items = $source->reviewItems()->with('sourceDocument')->latest('id')->get();

        return $items->map(function (ReviewItem $i) use ($currentYear): array {
            // 新旧の判定は分析材料と同じルール（PHPで確定）。投稿時期を優先し、無ければ在籍時期で見る
            $classification = $this->period->classifyReview($i->posted_period, $i->tenure_period, $currentYear);

            return [
                'id' => $i->id,
                'stance' => $i->stance,
                'postedPeriod' => $i->posted_period,
                'tenurePeriod' => $i->tenure_period,
                'category' => $i->category,
                'jobType' => $i->job_type,
                'sourceHost' => $this->hostOf($i->sourceDocument->url),
                'body' => $i->body,
                'periodLabel' => $this->period->label($classification),
                'isRecent' => $classification === ReviewPeriod::RECENT,
            ];
        })->all();
    }

    /** 手貼りソースは URL を持たないため、その場合はホスト名なし（null）を返す */
    private function hostOf(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        return parse_url($url, PHP_URL_HOST) ?: null;
    }
}
