<?php

namespace App\Services;

/**
 * 口コミの投稿時期テキスト（例: 2023年頃 / 令和6年 / 2024）から年を取り出し、
 * 「最近」「それ以前」を PHP で機械的に判定する。
 * 5年前の不満が今も続くかは別問題なので、新旧を分けて分析材料に渡すため。
 */
class ReviewPeriod
{
    /** これより新しい口コミを「最近」として扱う年数 */
    public const RECENT_YEARS = 2;

    /** 令和の元年（西暦）。令和N年 → 2018 + N */
    private const REIWA_BASE = 2018;

    /** 判定結果 */
    public const RECENT = 'recent';

    public const OLD = 'old';

    public const UNKNOWN = 'unknown';

    /**
     * 時期テキストに複数の年が含まれる場合、最も新しい年を返す（例: 「2015年〜2025年」→ 2025）。
     * 在籍レンジの開始年で判定すると、今も在籍中の人の声を「古い」と誤って扱うため。
     */
    public function latestYearOf(?string $periodText): ?int
    {
        $text = trim((string) $periodText);
        if ($text === '') {
            return null;
        }

        $years = [];

        if (preg_match_all('/(19|20)\d{2}/u', $text, $m) > 0) {
            $years = array_map('intval', $m[0]);
        }

        // 元号表記（令和6年 → 2024）。「元年」は1年として扱う
        if (preg_match_all('/令和\s*(\d+|元)\s*年/u', $text, $m) > 0) {
            foreach ($m[1] as $era) {
                $years[] = self::REIWA_BASE + ($era === '元' ? 1 : (int) $era);
            }
        }

        return $years === [] ? null : max($years);
    }

    /**
     * 口コミの新旧を判定する。いつ書かれたかを示す投稿時期（投稿日・回答日）を優先し、
     * 投稿時期が読み取れないときだけ在籍時期で代替する。
     */
    public function classifyReview(?string $postedPeriod, ?string $tenurePeriod, int $currentYear): string
    {
        $year = $this->latestYearOf($postedPeriod) ?? $this->latestYearOf($tenurePeriod);

        return $this->classifyYear($year, $currentYear);
    }

    private function classifyYear(?int $year, int $currentYear): string
    {
        if ($year === null) {
            return self::UNKNOWN;
        }

        return $year >= $currentYear - self::RECENT_YEARS ? self::RECENT : self::OLD;
    }

    public function label(string $classification): string
    {
        return match ($classification) {
            self::RECENT => '最近'.self::RECENT_YEARS.'年以内',
            self::OLD => self::RECENT_YEARS.'年より前',
            default => '時期不明',
        };
    }
}
