<?php

namespace App\Actions;

use App\Enums\NewsSeverity;
use App\Models\Company;
use App\Models\NewsScreening;
use App\Services\Ai\AiDecider;
use App\Services\UrlNormalizer;

/**
 * ニュース一覧に機械判定の結果を付けて並べ替える。判定はしない（ボタンの ScreenNewsAction だけが判定する）。
 *
 * 関連の有無・深刻度はしきい値で PHP が確定し、確率・score の生値は返さない。
 * 並び: 関連あり（深刻度 → 日付の降順）→ 未判定（日付の降順）→ 関連なし（日付の降順）。判定が無ければ RSS の順のまま。
 */
class BuildNewsScreeningAction
{
    private const GROUP_RELEVANT = 0;

    private const GROUP_UNSCREENED = 1;

    private const GROUP_IRRELEVANT = 2;

    public function __construct(
        private readonly AiDecider $decider,
        private readonly UrlNormalizer $urls,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $articles  NewsFeedService の記事（link・publishedAt を持つ）
     * @return array{articles: list<array<string, mixed>>, screening: array{enabled: bool, unscreened: int, seriousCount: int}}
     */
    public function execute(Company $company, array $articles): array
    {
        $screenings = $company->newsScreenings->keyBy('url_hash');

        $articles = array_map(function (array $article) use ($screenings) {
            $screening = $screenings->get(NewsScreening::hashOf($this->urls->normalize($article['link'])));

            return $article + ['screening' => $screening === null ? null : $this->present($screening)];
        }, $articles);

        return [
            'articles' => $this->sort($articles),
            'screening' => [
                'enabled' => $this->decider->isConfigured(),
                'unscreened' => count(array_filter($articles, fn (array $a) => $a['screening'] === null)),
                'seriousCount' => count(array_filter($articles, fn (array $a) => $a['screening'] !== null
                    && $a['screening']['relevant']
                    && $a['screening']['severity'] === NewsSeverity::Serious->value)),
            ],
        ];
    }

    /**
     * @return array{relevant: bool, kind: string, kindLabel: string, severity: string, severityLabel: string}
     */
    private function present(NewsScreening $screening): array
    {
        $severity = $this->severity($screening->severity_score);

        return [
            'relevant' => $screening->relevant_p >= $this->threshold('news_relevant_min'),
            'kind' => $screening->kind->value,
            'kindLabel' => $screening->kind->label(),
            'severity' => $severity->value,
            'severityLabel' => $severity->label(),
        ];
    }

    private function severity(float $score): NewsSeverity
    {
        return match (true) {
            $score >= $this->threshold('news_severity_serious_min') => NewsSeverity::Serious,
            $score >= $this->threshold('news_severity_caution_min') => NewsSeverity::Caution,
            default => NewsSeverity::Neutral,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $articles
     * @return list<array<string, mixed>>
     */
    private function sort(array $articles): array
    {
        if (! in_array(true, array_map(fn (array $a) => $a['screening'] !== null, $articles), true)) {
            return $articles;
        }

        // usort は安定なので、同順位は RSS の順のまま。日付の無い記事は同じ区分の末尾
        usort($articles, fn (array $a, array $b) => [$this->group($a), $this->rank($b), $b['publishedAt'] ?? '']
            <=> [$this->group($b), $this->rank($a), $a['publishedAt'] ?? '']);

        return $articles;
    }

    /** @param  array<string, mixed>  $article */
    private function group(array $article): int
    {
        return match (true) {
            $article['screening'] === null => self::GROUP_UNSCREENED,
            $article['screening']['relevant'] => self::GROUP_RELEVANT,
            default => self::GROUP_IRRELEVANT,
        };
    }

    /**
     * 深刻度の重さ。関連ありの中だけで効かせる（未判定・関連なしは日付だけで並べる）
     *
     * @param  array<string, mixed>  $article
     */
    private function rank(array $article): int
    {
        return $this->group($article) === self::GROUP_RELEVANT
            ? NewsSeverity::from($article['screening']['severity'])->rank()
            : 0;
    }

    private function threshold(string $key): float
    {
        $value = config("ai.decision.thresholds.{$key}");

        if (! is_numeric($value)) {
            throw new \RuntimeException("config/ai.php の decision.thresholds.{$key} が設定されていません");
        }

        return (float) $value;
    }
}
