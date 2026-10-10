<?php

namespace Tests\Unit;

use App\Services\ReviewPeriod;
use PHPUnit\Framework\TestCase;

class ReviewPeriodTest extends TestCase
{
    private ReviewPeriod $period;

    protected function setUp(): void
    {
        $this->period = new ReviewPeriod;
    }

    public function test_年レンジからは新しい方の年を取り出す(): void
    {
        $this->assertSame(2025, $this->period->latestYearOf('2015年〜2025年在籍'));
        $this->assertSame(2025, $this->period->latestYearOf('2022年入社・2025年退職'));
        $this->assertSame(2023, $this->period->latestYearOf('2023年頃'));
        $this->assertSame(2024, $this->period->latestYearOf('令和6年'), '元号表記も対象');
        $this->assertSame(2025, $this->period->latestYearOf('令和元年入社・2025年退職'), '西暦と元号が混在しても新しい方');
        $this->assertNull($this->period->latestYearOf(''));
        $this->assertNull($this->period->latestYearOf(null));
        $this->assertNull($this->period->latestYearOf('最近'));
    }

    public function test_口コミの新旧は投稿時期を優先して判定する(): void
    {
        $currentYear = 2026;

        $this->assertSame(
            ReviewPeriod::RECENT,
            $this->period->classifyReview('回答日 2026年01月', '2010年代前半に在籍', $currentYear),
            '在籍が古くても、書かれたのが最近なら最近の声として扱う',
        );
    }

    public function test_投稿時期が無ければ在籍時期で判定する(): void
    {
        $currentYear = 2026;

        $this->assertSame(
            ReviewPeriod::RECENT,
            $this->period->classifyReview('', '2015年〜2025年在籍', $currentYear),
            '在籍レンジは新しい方の年（2025年）で判定する',
        );
        $this->assertSame(
            ReviewPeriod::OLD,
            $this->period->classifyReview(null, '2010年〜2012年在籍', $currentYear),
        );
        $this->assertSame(
            ReviewPeriod::UNKNOWN,
            $this->period->classifyReview(null, null, $currentYear),
        );
    }

    public function test_表示ラベル(): void
    {
        $this->assertSame('最近2年以内', $this->period->label(ReviewPeriod::RECENT));
        $this->assertSame('2年より前', $this->period->label(ReviewPeriod::OLD));
        $this->assertSame('時期不明', $this->period->label(ReviewPeriod::UNKNOWN));
    }
}
