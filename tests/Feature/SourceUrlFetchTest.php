<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SourceDocument;
use App\Models\SourceRevision;
use App\Services\DnsResolver;
use App\Services\UrlTextFetcher;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SourceUrlFetchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // SSRFガードが実DNSを引くとテストがネットワーク依存になるため、
        // 存在しないテスト用ホストが公開IPに解決される偽リゾルバを束縛する。
        $this->fakeDns(['news.example.com' => ['93.184.216.34']]);

        // このファイルはURL取得の検証が目的で、派生ジョブ（条件抽出・口コミ分割）は対象外。
        // fake しないと sync キューが求人票保存の直後に ExtractConditionsJob を同期実行し、
        // 禁止バインドの codex 例外が「500応答のまま合格」という無音の失敗になる（実際に起きた）
        Queue::fake();
    }

    /**
     * 品質判定（実効400字以上）を通る現実的な本文。
     * 取得成功系のテストは「本文がある普通のページ」を相手にする必要があるため。
     */
    private function articleHtml(string $lead = '記事本文のテキストです。'): string
    {
        $body = $lead.str_repeat('当社は2026年度の新サービスについて、開発体制と提供時期の見通しを説明した。', 12);

        return "<html><body><h1>お知らせ</h1><p>{$body}</p></body></html>";
    }

    public function test_ur_l取得失敗はフォームエラーになりソースを作らない(): void
    {
        Http::fake(['news.example.com/*' => Http::response('', 403)]);
        $company = Company::create(['name' => '株式会社テスト']);

        $this->post(route('sources.store', $company), [
            'type' => 'news',
            'origin' => 'url',
            'url' => 'https://news.example.com/articles/1',
        ])->assertSessionHasErrors('url');

        $this->assertSame(0, SourceDocument::count(), '取得に失敗したらソースは作られない');
    }

    public function test_取得失敗のエラーは代替手段を案内する(): void
    {
        Http::fake(['news.example.com/*' => Http::response('', 403)]);
        $company = Company::create(['name' => '株式会社テスト']);

        $this->post(route('sources.store', $company), [
            'type' => 'news',
            'origin' => 'url',
            'url' => 'https://news.example.com/articles/1',
        ])->assertInvalid(['url' => '貼り付け']);
    }

    public function test_ur_l取得成功でソースが作られる(): void
    {
        Http::fake(['news.example.com/*' => Http::response($this->articleHtml())]);
        $company = Company::create(['name' => '株式会社テスト']);

        $this->post(route('sources.store', $company), [
            'type' => 'news',
            'origin' => 'url',
            'url' => 'https://news.example.com/articles/1',
        ])->assertSessionHasNoErrors();

        $this->assertStringContainsString('記事本文', SourceDocument::firstOrFail()->raw_text);
    }

    public function test_定型行だけの短いページはフォームエラーになりソースを作らない(): void
    {
        // ナビ・フッターしか無いページ（本文はJS描画待ち等）を「取得成功」として保存しない
        Http::fake(['news.example.com/*' => Http::response(<<<'HTML'
            <html><body>
            <nav>ログイン 新規会員登録 求人を探す 転職フェア マイページ</nav>
            <p>JavaScriptを有効にしてください。</p>
            <footer>プライバシーポリシー 利用規約 © Example</footer>
            </body></html>
            HTML)]);
        $company = Company::create(['name' => '株式会社テスト']);

        $this->post(route('sources.store', $company), [
            'type' => 'review',
            'origin' => 'url',
            'url' => 'https://news.example.com/companies/1',
        ])->assertSessionHasErrors('url');

        $this->assertSame(0, SourceDocument::count(), '本文が無いページはソースを作らない');
    }

    public function test_ログイン壁の口コミページはフォームエラーになりソースを作らない(): void
    {
        // 実データの OpenWork 企業ページ相当: 全体で2,600字ほどあるが中身はナビ文言と
        // 口コミ1件のティーザーで、ぼかした本文ごとに会員登録を促す
        $teaser = str_repeat('残業は月20時間ほどで、評価制度は上司との面談で決まる。', 20);
        Http::fake(['news.example.com/*' => Http::response(<<<HTML
            <html><body>
            <h1>株式会社テストの社員クチコミ</h1>
            <p>{$teaser}</p>
            <p>この続きを読むには会員登録が必要です。</p>
            <p>会員登録すると全ての社員クチコミが見られます。</p>
            <p>無料登録して続きを見るにはこちら。</p>
            </body></html>
            HTML)]);
        $company = Company::create(['name' => '株式会社テスト']);

        $this->post(route('sources.store', $company), [
            'type' => 'review',
            'origin' => 'url',
            'url' => 'https://news.example.com/companies/1',
        ])->assertInvalid(['url' => 'ログイン・会員登録が必要なページのようです']);

        $this->assertSame(0, SourceDocument::count(), 'ティーザーはソースを作らない');
    }

    public function test_会員登録の案内が少し混ざる長文記事は取り込める(): void
    {
        // 記事末尾にバナーが1つある普通のページを壁と誤判定しないこと
        $article = str_repeat('当社は新サービスの提供体制について詳細を説明した。', 60);
        Http::fake(['news.example.com/*' => Http::response(
            "<html><body><p>{$article}</p><p>続きを読むには会員登録が必要です。</p></body></html>"
        )]);
        $company = Company::create(['name' => '株式会社テスト']);

        $this->post(route('sources.store', $company), [
            'type' => 'news',
            'origin' => 'url',
            'url' => 'https://news.example.com/articles/2',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, SourceDocument::count());
    }

    public function test_除去した定型行の件数が保存される(): void
    {
        // ナビの定型行（ログイン / 新規会員登録）が2行落ちる求人票ページ
        $body = '年収は500万円から700万円。勤務地は東京本社でフレックスタイム制。'
            .str_repeat('残業は月20時間程度で、リモート勤務は週3日まで可能。年間休日は125日。', 12);
        Http::fake(['news.example.com/*' => Http::response(
            "<html><body>\n<nav>ログイン</nav>\n<nav>新規会員登録</nav>\n<p>{$body}</p>\n</body></html>"
        )]);
        $company = Company::create(['name' => '株式会社テスト']);

        $this->post(route('sources.store', $company), [
            'type' => 'jobposting',
            'origin' => 'url',
            'url' => 'https://news.example.com/jobs/1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, (int) SourceDocument::firstOrFail()->removed_lines);
    }

    public function test_貼り付け投入ではフォームが送ったurlを保存しない(): void
    {
        // URL取得失敗→「貼り付けに切り替える」の流れではフォームに url が残ったまま送られる。
        // 配列 union（+）の左辺優先で null 上書きが効かず、失敗した URL が同一性キーに
        // 紛れ込むバグがあった（貼り付けソースは url を持たないのが正）
        $company = Company::create(['name' => '株式会社テスト']);

        $this->post(route('sources.store', $company), [
            'type' => 'jobposting',
            'origin' => 'paste',
            'url' => 'https://news.example.com/failed-page',
            'raw_text' => $this->articleHtml('貼り付けた本文です。'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull(SourceDocument::firstOrFail()->url);
    }

    public function test_末尾スラッシュとutm付きの同一urlは新規作成されず履歴が積まれる(): void
    {
        Http::fake([
            'news.example.com/*' => Http::sequence()
                ->push($this->articleHtml('初回の本文です。'))
                ->push($this->articleHtml('書き換え後の本文です。')),
        ]);
        $company = Company::create(['name' => '株式会社テスト']);

        $this->post(route('sources.store', $company), [
            'type' => 'jobposting',
            'origin' => 'url',
            'url' => 'https://news.example.com/jobs/1',
        ])->assertSessionHasNoErrors();

        $this->post(route('sources.store', $company), [
            'type' => 'jobposting',
            'origin' => 'url',
            'url' => 'https://news.example.com/jobs/1/?utm_source=mail&utm_medium=email#top',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, SourceDocument::count(), '同じページは1レコードに寄せる');
        $this->assertSame(1, SourceRevision::count(), '本文が変われば履歴が積まれる');
        $this->assertSame('https://news.example.com/jobs/1', SourceDocument::firstOrFail()->url);
    }

    public function test_bingのapiclick_ur_lは実記事urlとして保存される(): void
    {
        // 取得自体は渡されたURL（apiclick）に対して行われ、保存時に実記事URLへ正規化される
        $this->fakeDns([
            'news.example.com' => ['93.184.216.34'],
            'www.bing.com' => ['93.184.216.40'],
        ]);
        Http::fake(['*' => Http::response($this->articleHtml())]);
        $company = Company::create(['name' => '株式会社テスト']);
        $target = rawurlencode('https://news.example.com/articles/123');

        $this->post(route('sources.store', $company), [
            'type' => 'news',
            'origin' => 'url',
            'url' => "http://www.bing.com/news/apiclick.aspx?ref=FexRss&tid=NEW-TID-999&url={$target}",
        ])->assertSessionHasNoErrors();

        $this->assertSame('https://news.example.com/articles/123', SourceDocument::firstOrFail()->url);
    }

    public function test_内部ip宛のurlはフォームエラーになり取得もしない(): void
    {
        Http::fake();
        $company = Company::create(['name' => '株式会社テスト']);

        $this->post(route('sources.store', $company), [
            'type' => 'news',
            'origin' => 'url',
            'url' => 'http://169.254.169.254/latest/meta-data/',
        ])->assertSessionHasErrors('url');

        $this->assertSame(0, SourceDocument::count(), '内部IP宛はソースを作らない');
        Http::assertNothingSent();
    }

    public function test_名前解決できないホストはフォームエラーになり取得もしない(): void
    {
        Http::fake();
        $company = Company::create(['name' => '株式会社テスト']);

        $this->post(route('sources.store', $company), [
            'type' => 'news',
            'origin' => 'url',
            'url' => 'https://unresolvable.example.com/x',
        ])->assertSessionHasErrors('url');

        $this->assertSame(0, SourceDocument::count());
        Http::assertNothingSent();
    }

    public function test_リダイレクト先が内部ipなら取得を中止する(): void
    {
        $this->fakeDns(['news.example.com' => ['93.184.216.34']]);
        // 入口URLは公開ホスト、リダイレクト先だけ内部IPという手口
        $this->fakeTransport([
            new Psr7Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            new Psr7Response(200, [], 'クラウドメタデータ'),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/内部ネットワーク/');

        app(UrlTextFetcher::class)->fetch('https://news.example.com/a');
    }

    public function test_リダイレクト先が公開ホストなら本文を取得する(): void
    {
        $this->fakeDns([
            'news.example.com' => ['93.184.216.34'],
            'other.example.com' => ['93.184.216.35'],
        ]);
        $this->fakeTransport([
            new Psr7Response(302, ['Location' => 'https://other.example.com/b']),
            new Psr7Response(200, [], $this->articleHtml('移転先の本文です。')),
        ]);

        $this->assertStringContainsString(
            '移転先の本文',
            app(UrlTextFetcher::class)->fetch('https://news.example.com/a'),
        );
    }

    public function test_検証済みipへ接続先を固定して1ホップずつ取得する(): void
    {
        $this->fakeDns([
            'news.example.com' => ['93.184.216.34', '93.184.216.35'],
            'other.example.com' => ['93.184.216.36'],
        ]);
        $sent = [];
        $this->capturingTransport($sent, [
            new Psr7Response(302, ['Location' => 'https://other.example.com/b']),
            new Psr7Response(200, [], $this->articleHtml('移転先の本文です。')),
        ]);

        app(UrlTextFetcher::class)->fetch('https://news.example.com/a');

        // 検証したIPを CURLOPT_RESOLVE で固定しないと、実接続で名前解決がもう一度走り
        // 2回目だけ内部IPを返す DNS リバインディングでガードをすり抜けられる
        $this->assertSame(
            ['news.example.com:443:93.184.216.34,93.184.216.35'],
            $sent[0]['curl'][CURLOPT_RESOLVE],
        );
        // リダイレクト先も追従任せにせず、自前で検証してから固定して取りに行く
        $this->assertSame(['other.example.com:443:93.184.216.36'], $sent[1]['curl'][CURLOPT_RESOLVE]);
        $this->assertFalse($sent[0]['allow_redirects'], 'Guzzleにリダイレクトを追わせない');
    }

    public function test_相対のリダイレクト先も解決して検証する(): void
    {
        $this->fakeDns(['news.example.com' => ['93.184.216.34']]);
        // Location が相対のときも同じホストの絶対URLとして解決できること
        $this->fakeTransport([
            new Psr7Response(302, ['Location' => '/moved/here']),
            new Psr7Response(200, [], $this->articleHtml('移動先の本文です。')),
        ]);

        $this->assertStringContainsString(
            '移動先の本文',
            app(UrlTextFetcher::class)->fetch('https://news.example.com/a'),
        );
    }

    public function test_リダイレクトが多すぎたら例外にする(): void
    {
        $this->fakeDns(['news.example.com' => ['93.184.216.34']]);
        $this->fakeTransport(array_fill(0, 8, new Psr7Response(302, ['Location' => 'https://news.example.com/next'])));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/リダイレクトが/');

        app(UrlTextFetcher::class)->fetch('https://news.example.com/a');
    }

    public function test_宣言サイズより短い応答は例外にする(): void
    {
        $this->fakeDns(['news.example.com' => ['93.184.216.34']]);
        // 途中で切れた本文を「正常に取得できた」ことにしてカルテへ保存しない
        $this->fakeTransport([
            new Psr7Response(200, ['Content-Length' => '99999'], '<html><body>途中まで</body></html>'),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/途中で切れました/');

        app(UrlTextFetcher::class)->fetch('https://news.example.com/a');
    }

    public function test_受信サイズが上限を超えたら例外にする(): void
    {
        $this->fakeDns(['news.example.com' => ['93.184.216.34']]);
        // 「あ」は3バイトなので約12MB。上限10MBを超える。
        $this->fakeTransport([new Psr7Response(200, [], str_repeat('あ', 4 * 1024 * 1024))]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/上限/');

        app(UrlTextFetcher::class)->fetch('https://news.example.com/a');
    }

    public function test_申告された本文長が上限を超えたら本文を受け取る前に中止する(): void
    {
        $this->fakeDns(['news.example.com' => ['93.184.216.34']]);
        $sent = [];
        // 本文は短いが Content-Length は 12MB を申告する。ヘッダの時点で打ち切れていれば
        // 本文の中身に関係なく上限超過で止まる
        $this->capturingTransport($sent, [
            new Psr7Response(200, ['Content-Length' => (string) (12 * 1024 * 1024)], 'short'),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/上限（10MB）を超えたため中止/u');

        app(UrlTextFetcher::class)->fetch('https://news.example.com/a');
    }

    public function test_申告が上限内なら通常どおり取得できる(): void
    {
        $this->fakeDns(['news.example.com' => ['93.184.216.34']]);
        $html = $this->articleHtml();
        $this->fakeTransport([
            new Psr7Response(200, ['Content-Length' => (string) strlen($html)], $html),
        ]);

        $this->assertStringContainsString('開発体制', app(UrlTextFetcher::class)->fetch('https://news.example.com/a'));
    }

    /**
     * リダイレクト検証・サイズ上限は Http::fake() では再現できない（fake は Guzzle の
     * リダイレクトミドルウェアを通らない）ため、実スタックに応答だけ差し込む。
     *
     * @param  array<int, Psr7Response>  $responses
     */
    private function fakeTransport(array $responses): void
    {
        Http::globalOptions(['handler' => HandlerStack::create(new MockHandler($responses))]);
    }

    /**
     * fakeTransport に加えて、ハンドラへ渡ったリクエストオプションを記録する。
     * 接続先の固定は curl オプションとして渡るだけなので、応答からは検証できない。
     *
     * @param  array<int, array{uri: string, curl: array<int, mixed>, allow_redirects: mixed}>  $sent
     * @param  array<int, Psr7Response>  $responses
     */
    private function capturingTransport(array &$sent, array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        // アロー関数だと外側の $sent を値でしか捕まえられないので通常の関数で参照を渡す
        $stack->push(function (callable $handler) use (&$sent) {
            return function ($request, array $options) use ($handler, &$sent) {
                $sent[] = [
                    'uri' => (string) $request->getUri(),
                    'curl' => $options['curl'] ?? [],
                    'allow_redirects' => $options['allow_redirects'] ?? null,
                ];

                return $handler($request, $options);
            };
        });

        Http::globalOptions(['handler' => $stack]);
    }

    /**
     * @param  array<string, array<int, string>>  $map  ホスト名 => 解決結果のIP
     */
    private function fakeDns(array $map): void
    {
        $this->app->instance(DnsResolver::class, new class($map) extends DnsResolver
        {
            /**
             * @param  array<string, array<int, string>>  $map
             */
            public function __construct(private readonly array $map) {}

            /**
             * @return array<int, string>
             */
            public function resolve(string $host): array
            {
                return $this->map[$host] ?? [];
            }
        });
    }
}
