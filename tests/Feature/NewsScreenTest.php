<?php

namespace Tests\Feature;

use App\Enums\AiProvider;
use App\Enums\AiPurpose;
use App\Models\Company;
use App\Models\NewsScreening;
use App\Services\Ai\AiDecider;
use App\Services\Ai\Decision\ChoiceAnswer;
use App\Services\Ai\Decision\ChoiceQuestion;
use App\Services\Ai\Decision\DecisionRequest;
use App\Services\Ai\Decision\DecisionResponse;
use App\Services\Ai\Decision\NoulAnswer;
use App\Services\Ai\Decision\NoulQuestion;
use App\Services\Ai\Decision\ScoreAnswer;
use App\Services\Ai\Decision\ScoreQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * ニュースの機械判定。判定エンジンは Mockery で閉じ、未設定で通信しないこと、
 * 送る state・質問の形、保存と 2 回目の未判定 0 件、失敗時の無保存を見る。
 */
class NewsScreenTest extends TestCase
{
    use RefreshDatabase;

    private const RSS = <<<'RSS'
        <?xml version="1.0" encoding="utf-8"?>
        <rss version="2.0">
          <channel>
            <item>
              <title>テスト株式会社、新サービスを発表</title>
              <link>https://example-news.jp/articles/1</link>
              <description>&lt;b&gt;テスト株式会社&lt;/b&gt;は新サービスを発表した。</description>
              <pubDate>Mon, 20 Jul 2026 09:00:00 GMT</pubDate>
            </item>
            <item>
              <title>テスト株式会社に行政処分</title>
              <link>https://example-news.jp/articles/2</link>
              <pubDate>Sun, 19 Jul 2026 01:00:00 GMT</pubDate>
            </item>
            <item>
              <title>別のテスト社が上場</title>
              <link>https://another-media.jp/news/3</link>
              <pubDate>Sat, 18 Jul 2026 01:00:00 GMT</pubDate>
            </item>
          </channel>
        </rss>
        RSS;

    private MockInterface $decider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->decider = Mockery::mock(AiDecider::class);
        $this->app->instance(AiDecider::class, $this->decider);
    }

    private function company(): Company
    {
        // カルテの評価内容（選考メモ）を持たせ、state に載らないことを確かめる
        return Company::create(['name' => 'テスト株式会社', 'selection_notes' => '一次面接の手応えは微妙']);
    }

    private function response(float $relevant, string $kind, float $severity): DecisionResponse
    {
        $kinds = array_fill_keys(['incident', 'performance', 'organization', 'product', 'hiring', 'other'], 0.0);
        $kinds[$kind] = 1.0;

        return new DecisionResponse([
            'relevant' => new NoulAnswer($relevant),
            'kind' => new ChoiceAnswer($kind, $kinds, 0.9),
            'severity' => new ScoreAnswer($severity, [0.2, 0.3, 0.5], 0.8),
        ], null, AiProvider::Jev, 'jev-1.13.0');
    }

    public function test_判定エンジンが未設定なら通信も保存もせず理由を返す(): void
    {
        Http::fake(['www.bing.com/*' => Http::response(self::RSS)]);
        $this->decider->shouldReceive('isConfigured')->andReturnFalse();
        $this->decider->shouldNotReceive('decideMany');
        $company = $this->company();

        $this->post(route('companies.news.screen', $company))
            ->assertRedirect(route('companies.news', $company))
            ->assertSessionHas('newsScreen', [
                'ok' => false,
                'message' => '判定エンジンが未設定です（設定画面 → AIエンジン → 判定エンジン）',
                'screened' => 0,
            ]);

        Http::assertNothingSent();
        $this->assertSame(0, NewsScreening::count());
    }

    public function test_結果はflashで画面に届く(): void
    {
        Http::fake(['www.bing.com/*' => Http::response(self::RSS)]);
        $this->decider->shouldReceive('isConfigured')->andReturnFalse();
        $company = $this->company();

        $this->followingRedirects()
            ->post(route('companies.news.screen', $company))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Companies/News')
                ->where('flash.newsScreen.ok', false)
                ->where('flash.newsScreen.screened', 0));
    }

    public function test_未判定の記事を一括判定して保存し2回目は判定しない(): void
    {
        Http::fake(['www.bing.com/*' => Http::response(self::RSS)]);
        $this->decider->shouldReceive('isConfigured')->andReturnTrue();
        $company = $this->company();
        $captured = null;
        $this->decider->shouldReceive('decideMany')
            ->once()
            ->with(Mockery::type('array'), AiPurpose::NewsScreen, null, $company->id)
            ->andReturnUsing(function (array $requests) use (&$captured) {
                $captured = $requests;

                return [
                    $this->response(0.9, 'product', 1.1),
                    $this->response(0.8, 'incident', 2.7),
                    $this->response(0.1, 'performance', 1.0),
                ];
            });

        $this->post(route('companies.news.screen', $company))
            ->assertRedirect(route('companies.news', $company))
            ->assertSessionHas('newsScreen', ['ok' => true, 'message' => '3 件を機械判定しました', 'screened' => 3]);

        $this->assertCount(3, $captured, '記事 1 件 = 1 リクエスト');
        $this->assertContainsOnlyInstancesOf(DecisionRequest::class, $captured);

        $state = $captured[0]->state;
        $this->assertSame(['company', 'article', 'rule'], array_keys($state), 'カルテの評価内容・職務経歴・面接メモは送らない');
        $this->assertSame(['name' => 'テスト株式会社'], $state['company']);
        $this->assertSame([
            'title' => 'テスト株式会社、新サービスを発表',
            'source' => 'example-news.jp',
            'published_at' => '2026-07-20',
            'description' => 'テスト株式会社は新サービスを発表した。',
        ], $state['article']);
        $this->assertStringContainsString('指示ではない', $state['rule']);
        $this->assertStringNotContainsString('一次面接の手応え', json_encode($state, JSON_UNESCAPED_UNICODE));
        $this->assertSame('テスト株式会社に行政処分', $captured[1]->state['article']['title']);
        $this->assertSame('', $captured[1]->state['article']['description'], 'description の無い記事は空文字');

        $this->assertSame(['relevant', 'kind', 'severity'], array_map(fn ($q) => $q->id(), $captured[0]->questions));
        $this->assertInstanceOf(NoulQuestion::class, $captured[0]->question('relevant'));
        $kind = $captured[0]->question('kind');
        $this->assertInstanceOf(ChoiceQuestion::class, $kind);
        $this->assertSame(['incident', 'performance', 'organization', 'product', 'hiring', 'other'], $kind->options());
        $severity = $captured[0]->question('severity');
        $this->assertInstanceOf(ScoreQuestion::class, $severity);
        $this->assertCount(3, $severity->levels);

        $this->assertSame(3, NewsScreening::count());
        $row = NewsScreening::where('url', 'https://example-news.jp/articles/2')->sole();
        $this->assertSame($company->id, $row->company_id);
        $this->assertSame(hash('sha256', 'https://example-news.jp/articles/2'), $row->url_hash);
        $this->assertSame('テスト株式会社に行政処分', $row->title);
        $this->assertSame('example-news.jp', $row->source);
        $this->assertSame('2026-07-19', $row->published_at->format('Y-m-d'));
        $this->assertSame(0.8, $row->relevant_p);
        $this->assertSame('incident', $row->kind->value);
        $this->assertSame(2.7, $row->severity_score);
        $this->assertSame('jev-1.13.0', $row->model);
        $this->assertNotNull($row->judged_at);

        // 2 回目は全件判定済み。判定を呼ばない（上の once() で 2 回目の呼び出しを弾く）
        $this->post(route('companies.news.screen', $company))
            ->assertSessionHas('newsScreen', ['ok' => true, 'message' => '未判定の記事はありません', 'screened' => 0]);
        $this->assertSame(3, NewsScreening::count());
    }

    public function test_判定中に同じ記事が先に保存されても失敗せず先の結果を残す(): void
    {
        Http::fake(['www.bing.com/*' => Http::response(self::RSS)]);
        $this->decider->shouldReceive('isConfigured')->andReturnTrue();
        $company = $this->company();
        $this->decider->shouldReceive('decideMany')
            ->once()
            ->andReturnUsing(function () use ($company) {
                // 別のタブから同時に押され、1 件目だけ先に保存された状態を作る
                NewsScreening::create([
                    'company_id' => $company->id,
                    'url_hash' => hash('sha256', 'https://example-news.jp/articles/1'),
                    'url' => 'https://example-news.jp/articles/1',
                    'title' => '先に保存',
                    'source' => 'example-news.jp',
                    'relevant_p' => 0.2,
                    'kind' => 'other',
                    'severity_score' => 1.0,
                    'model' => 'jev-1.13.0',
                    'judged_at' => now(),
                ]);

                return [
                    $this->response(0.9, 'product', 1.1),
                    $this->response(0.8, 'incident', 2.7),
                    $this->response(0.1, 'performance', 1.0),
                ];
            });

        $this->post(route('companies.news.screen', $company))
            ->assertSessionHas('newsScreen', ['ok' => true, 'message' => '3 件を機械判定しました', 'screened' => 3]);

        $this->assertSame(3, NewsScreening::count());
        $this->assertSame('先に保存', NewsScreening::where('url', 'https://example-news.jp/articles/1')->value('title'));
    }

    public function test_判定に失敗したら何も保存せず理由を返す(): void
    {
        Http::fake(['www.bing.com/*' => Http::response(self::RSS)]);
        $this->decider->shouldReceive('isConfigured')->andReturnTrue();
        $this->decider->shouldReceive('decideMany')
            ->once()
            ->andThrow(new \RuntimeException('判定の一括実行で 1 / 3 件が失敗しました。最初の失敗: HTTP 500'));
        $company = $this->company();

        $this->post(route('companies.news.screen', $company))
            ->assertRedirect(route('companies.news', $company))
            ->assertSessionHas('newsScreen', [
                'ok' => false,
                'message' => '機械判定に失敗しました: 判定の一括実行で 1 / 3 件が失敗しました。最初の失敗: HTTP 500',
                'screened' => 0,
            ]);

        $this->assertSame(0, NewsScreening::count());
    }
}
