<?php

namespace Tests\Feature;

use App\Enums\AiProvider;
use App\Models\AiSetting;
use App\Models\Company;
use App\Models\NewsScreening;
use App\Services\UrlNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ニュース画面への機械判定の反映。並び・状態の props・しきい値の境界を見る。
 * 画面の表示では判定を呼ばない（TestCase の ForbiddenAiDecider が呼ばれたら落ちる）。
 */
class NewsPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, string>  $items  パス => 日付（Y-m-d）。RSS の並び順
     */
    private function fakeRss(array $items): void
    {
        $xml = '';
        foreach ($items as $path => $date) {
            $pubDate = date(DATE_RSS, strtotime("{$date} 03:00:00 UTC"));
            $xml .= "<item><title>記事{$path}</title><link>https://example-news.jp/{$path}</link><pubDate>{$pubDate}</pubDate></item>";
        }

        Http::fake(['www.bing.com/*' => Http::response("<?xml version=\"1.0\"?><rss version=\"2.0\"><channel>{$xml}</channel></rss>")]);
    }

    private function screen(Company $company, string $path, float $relevant, float $severity, string $kind = 'incident'): void
    {
        $url = app(UrlNormalizer::class)->normalize("https://example-news.jp/{$path}");

        NewsScreening::create([
            'company_id' => $company->id,
            'url_hash' => hash('sha256', $url),
            'url' => $url,
            'title' => "記事{$path}",
            'source' => 'example-news.jp',
            'published_at' => null,
            'relevant_p' => $relevant,
            'kind' => $kind,
            'severity_score' => $severity,
            'model' => 'jev-1.13.0',
            'judged_at' => now(),
        ]);
    }

    public function test_判定が無ければrssの順のまま未判定の件数を返す(): void
    {
        $this->fakeRss(['a' => '2026-07-18', 'b' => '2026-07-20', 'c' => '2026-07-19']);
        $company = Company::create(['name' => 'テスト株式会社']);

        $this->get(route('companies.news', $company))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('articles.0.title', '記事a')
                ->where('articles.1.title', '記事b')
                ->where('articles.2.title', '記事c')
                ->where('articles.0.screening', null)
                ->where('screening', ['enabled' => false, 'unscreened' => 3, 'seriousCount' => 0]));
    }

    public function test_関連ありを深刻度と日付の順に並べ未判定を中間に関連なしを末尾に置く(): void
    {
        AiSetting::sole()->update(['decision_provider' => AiProvider::Jev]);
        $this->fakeRss([
            'a' => '2026-07-20', 'b' => '2026-07-19', 'c' => '2026-07-18', 'd' => '2026-07-17',
            'e' => '2026-07-16', 'f' => '2026-07-21', 'g' => '2026-07-22', 'h' => '2026-07-15',
        ]);
        $company = Company::create(['name' => 'テスト株式会社']);
        $this->screen($company, 'b', 0.9, 2.0);
        $this->screen($company, 'c', 0.2, 2.9);
        $this->screen($company, 'd', 0.9, 2.8);
        $this->screen($company, 'f', 0.9, 1.0, 'product');
        $this->screen($company, 'g', 0.1, 1.0);
        $this->screen($company, 'h', 0.7, 2.6);

        $this->get(route('companies.news', $company))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // 関連あり: 深刻（d・h は日付降順）→ 注意（b）→ 中立（f）/ 未判定: a → e / 関連なし: g → c
                ->where('articles', fn ($articles) => collect($articles)->pluck('title')->all() === [
                    '記事d', '記事h', '記事b', '記事f', '記事a', '記事e', '記事g', '記事c',
                ])
                ->where('articles.0.screening', [
                    'relevant' => true,
                    'kind' => 'incident',
                    'kindLabel' => '不祥事・訴訟・行政処分',
                    'severity' => 'serious',
                    'severityLabel' => '深刻',
                ])
                ->where('articles.3.screening.kindLabel', '製品・事業・提携')
                ->where('articles.3.screening.severityLabel', '中立')
                ->where('articles.4.screening', null)
                ->where('articles.7.screening.relevant', false)
                // 関連なしの深刻（c）は数えない
                ->where('screening', ['enabled' => true, 'unscreened' => 2, 'seriousCount' => 2]));
    }

    /**
     * @return array<string, array{0: float, 1: float, 2: bool, 3: string}>
     */
    public static function thresholdCases(): array
    {
        return [
            '深刻度 2.5 ちょうどは深刻' => [0.9, 2.5, true, 'serious'],
            '深刻度 2.49 は注意' => [0.9, 2.49, true, 'caution'],
            '深刻度 1.5 ちょうどは注意' => [0.9, 1.5, true, 'caution'],
            '深刻度 1.49 は中立' => [0.9, 1.49, true, 'neutral'],
            '関連度 0.5 ちょうどは関連あり' => [0.5, 1.0, true, 'neutral'],
            '関連度 0.49 は関連なし' => [0.49, 1.0, false, 'neutral'],
        ];
    }

    #[DataProvider('thresholdCases')]
    public function test_しきい値で関連の有無と深刻度を確定する(float $relevant, float $severity, bool $isRelevant, string $level): void
    {
        $this->fakeRss(['a' => '2026-07-20']);
        $company = Company::create(['name' => 'テスト株式会社']);
        $this->screen($company, 'a', $relevant, $severity);

        $this->get(route('companies.news', $company))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('articles.0.screening.relevant', $isRelevant)
                ->where('articles.0.screening.severity', $level)
                ->where('screening.unscreened', 0));
    }
}
