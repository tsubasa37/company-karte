<?php

namespace Tests\Feature;

use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Models\Company;
use App\Models\SourceDocument;
use App\Services\NewsFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class NewsFeedTest extends TestCase
{
    use RefreshDatabase;

    private const SAMPLE_RSS = <<<'RSS'
        <?xml version="1.0" encoding="utf-8"?>
        <rss version="2.0">
          <channel>
            <title>テスト株式会社 - Bing News</title>
            <item>
              <title>テスト株式会社、新サービスを発表</title>
              <link>https://example-news.jp/articles/123</link>
              <pubDate>Mon, 20 Jul 2026 09:00:00 GMT</pubDate>
            </item>
            <item>
              <title>テスト株式会社が資金調達</title>
              <link>https://another-media.jp/news/456</link>
              <pubDate>Sun, 19 Jul 2026 01:00:00 GMT</pubDate>
            </item>
          </channel>
        </rss>
        RSS;

    public function test_rs_sを記事一覧に変換する(): void
    {
        Http::fake(['www.bing.com/*' => Http::response(self::SAMPLE_RSS)]);

        $articles = $this->app->make(NewsFeedService::class)->search('テスト株式会社');

        $this->assertCount(2, $articles);
        $this->assertSame('テスト株式会社、新サービスを発表', $articles[0]['title']);
        $this->assertSame('https://example-news.jp/articles/123', $articles[0]['link']);
        $this->assertSame('example-news.jp', $articles[0]['source']);
        $this->assertSame('2026-07-20', $articles[0]['publishedAt']);
    }

    public function test_descriptionはタグを剥がして300字までにし無ければ空文字にする(): void
    {
        $long = str_repeat('あ', 400);
        $rss = <<<RSS
            <?xml version="1.0" encoding="utf-8"?>
            <rss version="2.0"><channel>
              <item><title>記事1</title><link>https://example-news.jp/1</link>
                <description>&lt;b&gt;テスト株式会社&lt;/b&gt;は  新サービスを
                発表した。</description></item>
              <item><title>記事2</title><link>https://example-news.jp/2</link><description>{$long}</description></item>
              <item><title>記事3</title><link>https://example-news.jp/3</link></item>
            </channel></rss>
            RSS;
        Http::fake(['www.bing.com/*' => Http::response($rss)]);

        $articles = $this->app->make(NewsFeedService::class)->search('テスト株式会社');

        $this->assertSame('テスト株式会社は 新サービスを 発表した。', $articles[0]['description']);
        $this->assertSame(str_repeat('あ', 300), $articles[1]['description']);
        $this->assertSame('', $articles[2]['description']);
    }

    public function test_httpとhttps以外のリンクは除外する(): void
    {
        $rss = <<<'RSS'
            <?xml version="1.0" encoding="utf-8"?>
            <rss version="2.0"><channel>
              <item><title>危険なリンク</title><link>javascript:alert(1)</link></item>
              <item><title>正常な記事</title><link>https://example-news.jp/ok</link></item>
            </channel></rss>
            RSS;
        Http::fake(['www.bing.com/*' => Http::response($rss)]);

        $articles = $this->app->make(NewsFeedService::class)->search('テスト株式会社');

        $this->assertCount(1, $articles);
        $this->assertSame('https://example-news.jp/ok', $articles[0]['link']);
    }

    public function test_rs_s取得失敗は例外にする(): void
    {
        Http::fake(['www.bing.com/*' => Http::response('', 503)]);

        $this->expectException(\RuntimeException::class);

        $this->app->make(NewsFeedService::class)->search('テスト株式会社');
    }

    public function test_ニュースページが表示される(): void
    {
        Http::fake(['www.bing.com/*' => Http::response(self::SAMPLE_RSS)]);
        $company = Company::create(['name' => 'テスト株式会社']);

        $this->get(route('companies.news', $company))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Companies/News')
                ->has('articles', 2)
                ->where('articles.0.title', 'テスト株式会社、新サービスを発表'));
    }

    public function test_取込済みの記事にはimportedとソースidが付く(): void
    {
        Http::fake(['www.bing.com/*' => Http::response(self::SAMPLE_RSS)]);
        $company = Company::create(['name' => 'テスト株式会社']);
        $source = SourceDocument::create([
            'company_id' => $company->id,
            'type' => SourceType::News,
            'origin' => SourceOrigin::Url,
            'url' => 'https://example-news.jp/articles/123',
            'raw_text' => '記事本文',
        ]);

        $this->get(route('companies.news', $company))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('articles.0.imported', true)
                ->where('articles.0.sourceId', $source->id)
                ->where('articles.1.imported', false)
                ->where('articles.1.sourceId', null));
    }

    public function test_bingの追跡idが変わっても取込済みと判定される(): void
    {
        // apiclick リンクの tid は取得のたびに変わるため、url= の実記事URLで照合する
        $target = rawurlencode('https://example-news.jp/articles/123');
        $rss = str_replace(
            '<link>https://example-news.jp/articles/123</link>',
            "<link>http://www.bing.com/news/apiclick.aspx?ref=FexRss&amp;tid=NEW-TID-999&amp;url={$target}</link>",
            self::SAMPLE_RSS,
        );
        Http::fake(['www.bing.com/*' => Http::response($rss)]);
        $company = Company::create(['name' => 'テスト株式会社']);
        $source = SourceDocument::create([
            'company_id' => $company->id,
            'type' => SourceType::News,
            'origin' => SourceOrigin::Url,
            // 過去の取り込み時に保存された、別の tid を持つ apiclick リンク
            'url' => 'http://www.bing.com/news/apiclick.aspx?ref=FexRss&tid=OLD-TID-111&url='.$target,
            'raw_text' => '記事本文',
        ]);

        $this->get(route('companies.news', $company))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('articles.0.imported', true)
                ->where('articles.0.sourceId', $source->id));
    }

    public function test_bingを偽装したホストのurlは正規化されない(): void
    {
        // evil.com/bing.com/news/apiclick はパスに bing.com を含むだけで Bing ではない。
        // url= を採用してしまうと実記事URLと誤って照合され imported が付いてしまう
        Http::fake(['www.bing.com/*' => Http::response(self::SAMPLE_RSS)]);
        $company = Company::create(['name' => 'テスト株式会社']);
        SourceDocument::create([
            'company_id' => $company->id,
            'type' => SourceType::News,
            'origin' => SourceOrigin::Url,
            'url' => 'https://evil.com/bing.com/news/apiclick.aspx?url='.rawurlencode('https://example-news.jp/articles/123'),
            'raw_text' => '記事本文',
        ]);

        $this->get(route('companies.news', $company))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('articles.0.imported', false)
                ->where('articles.0.sourceId', null));
    }
}
