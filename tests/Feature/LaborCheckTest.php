<?php

namespace Tests\Feature;

use App\Jobs\LaborCheckJob;
use App\Models\Company;
use App\Services\DnsResolver;
use App\Services\LaborViolationChecker;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LaborCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // PDF取得前の SSRF チェックで実DNSを引かせない（テストはオフラインで動かす）
        $this->fakeDns([
            'www.mhlw.go.jp' => ['203.0.113.10'],
            'labor.example.test' => ['203.0.113.11'],
        ]);
    }

    public function test_社名一致で注記が残り不一致は残らない(): void
    {
        $hit = Company::create(['name' => '株式会社芙蓉テスト工業']);
        $miss = Company::create(['name' => '株式会社ヒットなし商事']);

        // PDFは文字間に空白が入ることがあるため、空白入りでも一致することを確認する
        $pdfText = "東京労働局　芙 蓉 テ ス ト 工 業 株式会社\n労働基準法第32条違反　長時間労働に係る事案";

        $this->app->make(LaborViolationChecker::class)->matchCompanies($pdfText);

        $this->assertNotNull($hit->fresh()->labor_note);
        $this->assertStringContainsString('要原文確認', $hit->fresh()->labor_note);
        $this->assertNull($miss->fresh()->labor_note);
        $this->assertNotNull($miss->fresh()->labor_checked_at, '不一致でも突合済みの記録は残る');
    }

    public function test_全角英数の_pd_f表記でも社名一致する(): void
    {
        // 厚労省の公表事案PDFは全角英数が多く、登録名が半角だと片側正規化では取りこぼす
        $hit = Company::create(['name' => 'ABCテスト工業株式会社']);
        $miss = Company::create(['name' => '株式会社ヒットなし商事']);

        $this->app->make(LaborViolationChecker::class)
            ->matchCompanies('東京労働局　ＡＢＣテスト工業株式会社　労働基準法第32条違反');

        $this->assertNotNull($hit->fresh()->labor_note, '全角英数のPDF表記でも一致すること');
        $this->assertNull($miss->fresh()->labor_note);
    }

    public function test_半角カナの_pd_f表記でも社名一致する(): void
    {
        $company = Company::create(['name' => '株式会社テスティング工業']);

        $this->app->make(LaborViolationChecker::class)
            ->matchCompanies('ﾃｽﾃｨﾝｸﾞ工業株式会社　労働基準法第32条違反');

        $this->assertNotNull($company->fresh()->labor_note);
    }

    public function test_中黒の有無で社名一致を取りこぼさない(): void
    {
        $withDot = Company::create(['name' => '株式会社ジェイ・ティテスト']);
        $withoutDot = Company::create(['name' => 'ジェイティテスト株式会社']);

        // PDF側は中黒なし表記。登録名の中黒あり/なしのどちらでも一致すること
        $this->app->make(LaborViolationChecker::class)
            ->matchCompanies('ジェイティテスト株式会社　労働基準法第32条違反');

        $this->assertNotNull($withDot->fresh()->labor_note);
        $this->assertNotNull($withoutDot->fresh()->labor_note);
    }

    public function test_無関係な_pd_fなら全角社名でも注記は付かない(): void
    {
        $company = Company::create(['name' => 'ABCテスト工業株式会社']);

        $this->app->make(LaborViolationChecker::class)
            ->matchCompanies('東京労働局　ＸＹＺ無関係産業株式会社　労働基準法第32条違反');

        $this->assertNull($company->fresh()->labor_note);
        $this->assertNotNull($company->fresh()->labor_checked_at);
    }

    public function test_正規化後3文字未満の社名は突合しない(): void
    {
        // 中黒・空白を除くと2文字になる社名は誤ヒットが多いため、PDFに含まれても突合しない
        $company = Company::create(['name' => '株式会社A・B']);

        $this->app->make(LaborViolationChecker::class)
            ->matchCompanies('東京労働局　Ａ・Ｂ株式会社　労働基準法第32条違反');

        $this->assertNull($company->fresh()->labor_note);
        $this->assertNotNull($company->fresh()->labor_checked_at, '突合対象外でも確認済みの記録は残る');
    }

    public function test_再突合でヒットが消えたら注記も消える(): void
    {
        $company = Company::create([
            'name' => '株式会社テスト重工',
            'labor_note' => '過去のヒット',
        ]);

        $this->app->make(LaborViolationChecker::class)->matchCompanies('無関係のPDFテキスト');

        $this->assertNull($company->fresh()->labor_note);
    }

    public function test_消えた注記は履歴に残る(): void
    {
        // 公表リストは掲載期間が約1年でローテーションするため、一度一致した社名は
        // 次回の突合で一致しなくなる。「過去に載っていた」事実が黙って消えないこと
        $checker = $this->app->make(LaborViolationChecker::class);
        $company = Company::create(['name' => '株式会社芙蓉テスト工業']);

        $checker->matchCompanies('芙蓉テスト工業株式会社　労働基準法第32条違反');
        $hitNote = $company->fresh()->labor_note;
        $this->assertNotNull($hitNote);
        $this->assertNull($company->fresh()->labor_note_history, '一致中は履歴を作らない');

        $checker->matchCompanies('掲載が入れ替わった後のPDFテキスト');

        $fresh = $company->fresh();
        $this->assertNull($fresh->labor_note);
        $this->assertCount(1, $fresh->labor_note_history);
        $this->assertSame($hitNote, $fresh->labor_note_history[0]['note'], '消えた注記の中身をそのまま残す');
        $this->assertStringContainsString('社名一致なし', $fresh->labor_note_history[0]['reason']);
        $this->assertNotNull($fresh->labor_note_history[0]['cleared_at']);
    }

    public function test_履歴は上書きされず追記される(): void
    {
        $checker = $this->app->make(LaborViolationChecker::class);
        $company = Company::create([
            'name' => '株式会社芙蓉テスト工業',
            'labor_note_history' => [
                ['note' => '以前のヒット', 'reason' => '再突合で一致なし', 'cleared_at' => '2026-01-01T00:00:00+09:00'],
            ],
        ]);

        $checker->matchCompanies('芙蓉テスト工業株式会社　労働基準法第32条違反');
        $checker->matchCompanies('掲載が入れ替わった後のPDFテキスト');

        $history = $company->fresh()->labor_note_history;
        $this->assertCount(2, $history, '既存の履歴を消さずに追記する');
        $this->assertSame('以前のヒット', $history[0]['note']);
        $this->assertStringContainsString('要原文確認', $history[1]['note']);
    }

    public function test_一致なしのままの再突合では履歴が増えない(): void
    {
        $checker = $this->app->make(LaborViolationChecker::class);
        $company = Company::create(['name' => '株式会社ヒットなし商事']);

        $checker->matchCompanies('無関係のPDFテキスト');
        $checker->matchCompanies('無関係のPDFテキスト');

        $this->assertNull($company->fresh()->labor_note_history, '消える注記が無ければ履歴は作らない');
    }

    public function test_突合対象外の社名でも消えた注記は履歴に残る(): void
    {
        // 正規化後3文字未満のガードで抜ける経路でも、過去のヒットは黙って消さない
        $company = Company::create(['name' => '株式会社A・B', 'labor_note' => '過去のヒット']);

        $this->app->make(LaborViolationChecker::class)
            ->matchCompanies('東京労働局　Ａ・Ｂ株式会社　労働基準法第32条違反');

        $fresh = $company->fresh();
        $this->assertNull($fresh->labor_note);
        $this->assertCount(1, $fresh->labor_note_history);
        $this->assertSame('過去のヒット', $fresh->labor_note_history[0]['note']);
        $this->assertStringContainsString('突合対象外', $fresh->labor_note_history[0]['reason']);
    }

    public function test_履歴の追記でも企業のupdated_atは変わらない(): void
    {
        $company = Company::create(['name' => '株式会社テスト重工', 'labor_note' => '過去のヒット']);
        $original = $company->fresh()->updated_at;

        $this->travel(1)->hours();

        $this->app->make(LaborViolationChecker::class)->matchCompanies('無関係のPDFテキスト');

        $fresh = $company->fresh();
        $this->assertCount(1, $fresh->labor_note_history);
        $this->assertTrue($original->equalTo($fresh->updated_at), '履歴を積んでも updated_at を動かさない');
    }

    public function test_無関係な_pd_fではなく公表事案_pd_fを選ぶ(): void
    {
        $html = <<<'HTML'
            <a href="/content/navigation.pdf">パンフレット（PDF）</a>
            <a href="/content/related-2020.pdf">関連資料</a>
            <a href="/content/kouhyou.pdf">労働基準関係法令違反に係る公表事案（令和8年6月分）</a>
            HTML;

        Http::fake([
            'www.mhlw.go.jp/kinkyu/*' => Http::response($html),
            'www.mhlw.go.jp/content/*' => Http::response('dummy'),
        ]);

        // PDF本体はダミーのためパースで失敗するが、その前にどのURLを選んだかを検証する
        try {
            $this->app->make(LaborViolationChecker::class)->checkAll();
        } catch (\Throwable) {
        }

        Http::assertSent(fn ($request) => $request->url() === 'https://www.mhlw.go.jp/content/kouhyou.pdf');
        Http::assertNotSent(fn ($request) => $request->url() === 'https://www.mhlw.go.jp/content/navigation.pdf');
    }

    public function test_公表事案_pd_fのリンクが無ければ例外(): void
    {
        Http::fake([
            'www.mhlw.go.jp/kinkyu/*' => Http::response('<a href="/content/other.pdf">別の資料</a>'),
        ]);

        $this->expectException(\RuntimeException::class);

        $this->app->make(LaborViolationChecker::class)->checkAll();
    }

    public function test_掲載_ur_lは_configで差し替えられる(): void
    {
        config(['services.labor.index_url' => 'https://labor.example.test/moved.html']);

        Http::fake([
            'labor.example.test/moved.html' => Http::response(
                '<a href="https://labor.example.test/kouhyou.pdf">労働基準関係法令違反に係る公表事案</a>'
            ),
            'labor.example.test/kouhyou.pdf' => Http::response('dummy'),
        ]);

        // PDF本体はダミーのためパースで失敗するが、その前に config のURLへ取得に行くことを検証する
        try {
            $this->app->make(LaborViolationChecker::class)->checkAll();
        } catch (\Throwable) {
        }

        Http::assertSent(fn ($request) => $request->url() === 'https://labor.example.test/moved.html');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'mhlw.go.jp'));
    }

    public function test_掲載ページと別オリジンの_pd_fリンクは取得しない(): void
    {
        Http::fake([
            'www.mhlw.go.jp/kinkyu/*' => Http::response(
                '<a href="https://evil.example.com/kouhyou.pdf">労働基準関係法令違反に係る公表事案</a>'
            ),
            '*' => Http::response('dummy'),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/別オリジン/');

        try {
            $this->app->make(LaborViolationChecker::class)->checkAll();
        } finally {
            Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example.com'));
        }
    }

    public function test_内部ipに解決されるホストの_pd_fは取得しない(): void
    {
        // 掲載ページと同オリジンでも、解決先が内部アドレスなら取得しない（PublicUrlGuard を通す）
        config(['services.labor.index_url' => 'https://labor.example.test/moved.html']);
        $this->fakeDns(['labor.example.test' => ['127.0.0.1']]);

        Http::fake([
            'labor.example.test/moved.html' => Http::response(
                '<a href="/kouhyou.pdf">労働基準関係法令違反に係る公表事案</a>'
            ),
            '*' => Http::response('dummy'),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/内部ネットワーク/');

        try {
            $this->app->make(LaborViolationChecker::class)->checkAll();
        } finally {
            Http::assertNotSent(fn ($request) => str_contains($request->url(), 'kouhyou.pdf'));
        }
    }

    public function test_公表事案_pd_fは検証済みipへ接続を固定して取得する(): void
    {
        config(['services.labor.index_url' => 'https://labor.example.test/moved.html']);
        $sent = [];
        $this->capturingTransport($sent, [
            new Psr7Response(200, [], '<a href="/kouhyou.pdf">労働基準関係法令違反に係る公表事案</a>'),
            new Psr7Response(200, [], 'PDFダミー'),
        ]);

        // PDF本体はダミーのためパースで失敗するが、ここで見たいのは取得時の接続先固定
        try {
            $this->app->make(LaborViolationChecker::class)->checkAll();
        } catch (\Throwable) {
        }

        // ガードを通しただけでホスト名のまま取りに行くと、実接続で名前解決がもう一度走り、
        // 2回目だけ内部IPを返す DNS リバインディングで検証をすり抜けられる
        $this->assertSame(
            ['labor.example.test:443:203.0.113.11'],
            $sent[1]['curl'][CURLOPT_RESOLVE] ?? null,
            '公表事案PDFも検証済みIPへ固定して取得すること',
        );
        $this->assertFalse($sent[1]['allow_redirects'], 'Guzzleにリダイレクトを追わせない（1ホップずつ検証する）');
    }

    public function test_突合しても企業のupdated_atは変わらない(): void
    {
        $company = Company::create(['name' => '株式会社芙蓉テスト工業']);
        $short = Company::create(['name' => '株式会社あ']); // 正規化後2文字以下の分岐も確認する
        $original = $company->fresh()->updated_at;
        $originalShort = $short->fresh()->updated_at;

        $this->travel(1)->hours();

        $this->app->make(LaborViolationChecker::class)
            ->matchCompanies('芙蓉テスト工業株式会社 労働基準法第32条違反');

        $fresh = $company->fresh();
        $this->assertTrue($original->equalTo($fresh->updated_at), '突合で updated_at を動かさない');
        $this->assertTrue($originalShort->equalTo($short->fresh()->updated_at), '短い社名の分岐でも updated_at を動かさない');
        $this->assertNotNull($fresh->labor_note, '突合結果の書き込み自体は行われる');
        $this->assertNotNull($fresh->labor_checked_at);
    }

    public function test_突合ボタンでジョブが投入される(): void
    {
        Queue::fake();
        Company::create(['name' => '株式会社テスト']);

        $this->post(route('labor.check'))->assertRedirect();

        Queue::assertPushed(LaborCheckJob::class);
    }

    public function test_企業ゼロならエラー(): void
    {
        Queue::fake();

        $this->post(route('labor.check'))->assertSessionHasErrors('labor');
        Queue::assertNothingPushed();
    }

    /**
     * 実スタックに応答だけ差し込み、ハンドラへ渡ったリクエストオプションを記録する。
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
     * 実DNSを引かない偽リゾルバを束縛する。
     *
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
