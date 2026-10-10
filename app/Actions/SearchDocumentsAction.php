<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\OpenIssue;
use App\Models\ReviewItem;
use App\Models\SourceDocument;
use Illuminate\Support\Collection;

/**
 * 取り込んだ材料（ソース本文・口コミ・論点・選考メモ）を横断して検索する。
 *
 * FTS5 は使わない。SQLite に載っている trigram トークナイザは3文字未満の語を引けず、
 * 「残業」「年収」「休日」のような2文字の検索語が全滅するため。データ量は自分専用の規模
 * （全社合計で数MB）なので LIKE の全表走査で足りる。
 */
class SearchDocumentsAction
{
    /** ヒット箇所の前後に付ける文字数 */
    private const CONTEXT_CHARS = 60;

    /** 1件のレコードから取り出す抜粋の最大数（同じ本文の中の複数ヒット） */
    private const SNIPPETS_PER_HIT = 3;

    /** 種別ごとの取得上限。超えた分は件数だけ出す */
    private const HITS_PER_KIND = 50;

    /** 検索語の最小文字数（1文字だと全件が並ぶだけで使えない） */
    public const MIN_QUERY_LENGTH = 2;

    /**
     * @return array<string, mixed>
     */
    public function execute(?string $query): array
    {
        $query = trim((string) $query);

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return [
                'query' => $query,
                'groups' => [],
                'total' => 0,
                // 空検索と「該当なし」を画面で区別する
                'tooShort' => $query !== '',
            ];
        }

        $hits = collect()
            ->concat($this->sourceHits($query))
            ->concat($this->reviewHits($query))
            ->concat($this->issueHits($query))
            ->concat($this->selectionNoteHits($query));

        $companies = Company::query()
            ->whereIn('id', $hits->pluck('companyId')->unique())
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        $groups = $hits
            ->groupBy('companyId')
            ->map(fn (Collection $rows, int $companyId) => [
                'companyId' => $companyId,
                'companyName' => $companies[$companyId]->name,
                'hits' => $rows->values()->all(),
                'count' => $rows->count(),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        return [
            'query' => $query,
            'groups' => $groups,
            'total' => $hits->count(),
            'tooShort' => false,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function sourceHits(string $query): Collection
    {
        return SourceDocument::query()
            ->where(fn ($q) => $this
                ->contains($q, 'raw_text', $query)
                ->orWhereRaw(self::LIKE_SQL('title'), [$this->like($query)]))
            ->limit(self::HITS_PER_KIND)
            ->get()
            ->map(fn (SourceDocument $s) => [
                'kind' => 'source',
                'kindLabel' => $s->type->label(),
                'companyId' => $s->company_id,
                'id' => $s->id,
                'title' => $s->title,
                'snippets' => $this->snippets((string) $s->raw_text, $query),
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function reviewHits(string $query): Collection
    {
        return ReviewItem::query()
            ->whereRaw(self::LIKE_SQL('body'), [$this->like($query)])
            ->limit(self::HITS_PER_KIND)
            ->get()
            ->map(fn (ReviewItem $r) => [
                'kind' => 'review',
                'kindLabel' => '口コミ',
                'companyId' => $r->company_id,
                // 口コミ本文は元のソース詳細ページで読む
                'id' => $r->source_document_id,
                'title' => $r->posted_period,
                'snippets' => $this->snippets((string) $r->body, $query),
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function issueHits(string $query): Collection
    {
        return OpenIssue::query()
            ->where(fn ($q) => $this
                ->contains($q, 'claim', $query)
                ->orWhereRaw(self::LIKE_SQL('resolution_note'), [$this->like($query)]))
            ->limit(self::HITS_PER_KIND)
            ->get()
            ->map(fn (OpenIssue $i) => [
                'kind' => 'issue',
                'kindLabel' => '論点（'.$i->status->label().'）',
                'companyId' => $i->company_id,
                'id' => $i->id,
                'title' => null,
                'snippets' => $this->snippets($i->claim."\n".(string) $i->resolution_note, $query),
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function selectionNoteHits(string $query): Collection
    {
        return Company::query()
            ->whereRaw(self::LIKE_SQL('selection_notes'), [$this->like($query)])
            ->limit(self::HITS_PER_KIND)
            ->get()
            ->map(fn (Company $c) => [
                'kind' => 'note',
                'kindLabel' => '選考メモ',
                'companyId' => $c->id,
                'id' => $c->id,
                'title' => null,
                'snippets' => $this->snippets((string) $c->selection_notes, $query),
            ]);
    }

    /**
     * LIKE 句。SQLite は既定でエスケープ文字を持たないため、ESCAPE を明示しないと
     * 検索語に含まれる % と _ がワイルドカードとして効いてしまう（「100%」で全件一致する）。
     */
    private static function LIKE_SQL(string $column): string
    {
        return "{$column} LIKE ? ESCAPE '\\'";
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Database\Query\Builder  $builder
     */
    private function contains(mixed $builder, string $column, string $query): mixed
    {
        return $builder->whereRaw(self::LIKE_SQL($column), [$this->like($query)]);
    }

    /** 検索語自体が持つワイルドカードを、文字そのものとして扱わせる */
    private function like(string $query): string
    {
        return '%'.addcslashes($query, '%_\\').'%';
    }

    /**
     * ヒット箇所の前後を切り出す。切り出し位置は PHP で確定し、画面では加工しない。
     *
     * @return array<int, array{before: string, match: string, after: string}>
     */
    private function snippets(string $body, string $query): array
    {
        $snippets = [];
        $offset = 0;

        while (count($snippets) < self::SNIPPETS_PER_HIT) {
            $pos = mb_stripos($body, $query, $offset);

            if ($pos === false) {
                break;
            }

            $start = max(0, $pos - self::CONTEXT_CHARS);
            $snippets[] = [
                'before' => ($start > 0 ? '…' : '').mb_substr($body, $start, $pos - $start),
                // 実際にヒットした原文（大文字小文字の違いをそのまま見せる）
                'match' => mb_substr($body, $pos, mb_strlen($query)),
                'after' => mb_substr($body, $pos + mb_strlen($query), self::CONTEXT_CHARS).'…',
            ];
            $offset = $pos + mb_strlen($query);
        }

        return $snippets;
    }
}
