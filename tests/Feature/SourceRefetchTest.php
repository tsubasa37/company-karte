<?php

namespace Tests\Feature;

use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Models\Company;
use App\Models\SourceDocument;
use App\Models\SourceRevision;
use App\Services\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SourceRefetchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake(); // 求人票の再取得で条件抽出ジョブが積まれるため（codex を走らせない）
        $this->fakeDns(['jobs.example.com' => ['93.184.216.34']]);
    }

    /** 品質判定（実効400字以上）を通る現実的な求人票本文 */
    private function jobHtml(string $salary): string
    {
        $body = "年収は{$salary}。".str_repeat('勤務地は東京本社でフレックスタイム制。年間休日は125日で、住宅手当の支給がある。', 12);

        return "<html><body><h1>エンジニア募集</h1><p>{$body}</p></body></html>";
    }

    private function source(Company $company, string $rawText, ?string $url = 'https://jobs.example.com/1'): SourceDocument
    {
        return $company->sourceDocuments()->create([
            'type' => SourceType::JobPosting,
            'origin' => $url === null ? SourceOrigin::Paste : SourceOrigin::Url,
            'url' => $url,
            'raw_text' => $rawText,
        ]);
    }

    public function test_再取得で本文が変われば履歴が1件増える(): void
    {
        Http::fake(['jobs.example.com/*' => Http::response($this->jobHtml('450万円〜600万円'))]);
        $company = Company::create(['name' => '株式会社テスト']);
        $source = $this->source($company, '年収は500万円〜700万円。');

        $this->post(route('sources.refetch', $source))->assertSessionHasNoErrors();

        $this->assertSame(1, SourceRevision::count());
        $this->assertStringContainsString('500万円', SourceRevision::firstOrFail()->raw_text, '旧本文が履歴に残る');
        $this->assertStringContainsString('450万円', $source->fresh()->raw_text, '新本文で上書きされる');
        $this->assertSame(1, SourceDocument::count(), '再取得で重複レコードを作らない');
    }

    public function test_再取得で本文が同じなら履歴は増えない(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $html = $this->jobHtml('500万円〜700万円');
        // 応答オブジェクトを使い回すとボディのストリームが2回目に空になるため毎回作る
        Http::fake(['jobs.example.com/*' => fn () => Http::response($html)]);

        // 1回目の取得で保存された本文（クリーニング後）と同じものが返る状態を作る
        $source = $this->source($company, '仮の本文');
        $this->post(route('sources.refetch', $source))->assertSessionHasNoErrors();
        $this->assertSame(1, SourceRevision::count());

        $this->post(route('sources.refetch', $source))->assertSessionHasNoErrors();

        $this->assertSame(1, SourceRevision::count(), '同じ本文では履歴を増やさない');
    }

    public function test_再取得の失敗は既存本文を保持してエラーを返す(): void
    {
        Http::fake(['jobs.example.com/*' => Http::response('', 403)]);
        $company = Company::create(['name' => '株式会社テスト']);
        $source = $this->source($company, '年収は500万円〜700万円。');

        $this->post(route('sources.refetch', $source))
            ->assertInvalid(['refetch' => '貼り付け']);

        $this->assertSame('年収は500万円〜700万円。', $source->fresh()->raw_text, '失敗時は既存本文を残す');
        $this->assertSame(0, SourceRevision::count());
    }

    public function test_ログイン壁になったページの再取得は既存本文を上書きしない(): void
    {
        // 求人が締め切られて会員登録ページに変わった場合など
        $teaser = str_repeat('この求人は掲載を終了しました。', 20);
        Http::fake(['jobs.example.com/*' => Http::response(
            "<html><body><p>{$teaser}</p><p>会員登録すると似た求人が見られます。</p>"
            .'<p>続きを見るには無料登録が必要です。</p><p>ログインしてお気に入りを確認。</p></body></html>'
        )]);
        $company = Company::create(['name' => '株式会社テスト']);
        $source = $this->source($company, '年収は500万円〜700万円。');

        $this->post(route('sources.refetch', $source))->assertInvalid(['refetch']);

        $this->assertSame('年収は500万円〜700万円。', $source->fresh()->raw_text);
    }

    public function test_ur_lの無いソースは再取得できない(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $source = $this->source($company, '手貼りした求人票の本文', null);

        $this->post(route('sources.refetch', $source))->assertNotFound();

        Http::assertNothingSent();
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
