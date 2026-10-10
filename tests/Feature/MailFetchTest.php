<?php

namespace Tests\Feature;

use App\Actions\FetchMailAction;
use App\Jobs\IngestMailJob;
use App\Models\GoogleCredential;
use App\Models\MailIngest;
use App\Models\MailSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Gmail からの定期取得。
 * 「該当するメールだけを取りに行くこと」と「未設定を黙って0件にしないこと」が要点。
 */
class MailFetchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MailSetting::create([
            'google_client_id' => 'test.apps.googleusercontent.com',
            'google_client_secret' => 'test-secret',
            'allowed_domains' => 'bizreach.jp,doda.jp',
        ]);
        config([
            'services.mail_ingest.window_days' => 7,
            'services.mail_ingest.max_messages' => 20,
        ]);
    }

    private function connect(): GoogleCredential
    {
        return GoogleCredential::create([
            'refresh_token' => 'refresh-1',
            'access_token' => 'access-1',
            'access_token_expires_at' => now()->addHour(),
        ]);
    }

    public function test_許可ドメインと期間でサーバー側に検索クエリを投げる(): void
    {
        Queue::fake();
        $this->connect();
        Http::fake(['gmail.googleapis.com/*' => Http::response(['messages' => []])]);

        app(FetchMailAction::class)->execute();

        Http::assertSent(function ($request) {
            $query = urldecode($request->url());

            return str_contains($query, 'from:(@bizreach.jp OR @doda.jp)')
                && str_contains($query, 'newer_than:7d');
        });
    }

    public function test_新規メールだけ取り込みジョブに積まれる(): void
    {
        Queue::fake();
        $this->connect();
        MailIngest::create([
            'external_id' => 'already-1',
            'from_domain' => 'bizreach.jp',
            'subject' => '取り込み済み',
            'received_at' => now(),
            'mail_kind' => 'scout',
        ]);
        Http::fake(['gmail.googleapis.com/*' => Http::response([
            'messages' => [['id' => 'already-1'], ['id' => 'new-1'], ['id' => 'new-2']],
        ])]);

        $result = app(FetchMailAction::class)->execute();

        $this->assertSame(3, $result['found']);
        $this->assertSame(2, $result['queued']);
        Queue::assertPushed(IngestMailJob::class, 2);
        Queue::assertPushed(fn (IngestMailJob $job) => $job->messageId === 'new-1');
        Queue::assertNotPushed(fn (IngestMailJob $job) => $job->messageId === 'already-1');
    }

    /**
     * 本物の Gmail と同じく maxResults で切り、続きがあれば nextPageToken を返す偽サーバー。
     * pageToken はページ先頭のオフセットとして扱う。
     *
     * @param  array<int, string>  $mailbox  新しい順に並んだ受信箱
     */
    private function fakeGmailPages(array $mailbox): void
    {
        Http::fake(['gmail.googleapis.com/*' => function ($request) use ($mailbox) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);
            $offset = (int) ($params['pageToken'] ?? 0);
            $page = array_slice($mailbox, $offset, (int) ($params['maxResults'] ?? 100));

            $body = ['messages' => array_map(fn (string $id) => ['id' => $id], $page)];

            if ($offset + count($page) < count($mailbox)) {
                $body['nextPageToken'] = (string) ($offset + count($page));
            }

            return Http::response($body);
        }]);
    }

    public function test_一覧はnext_page_tokenを辿って上限より奥まで見に行く(): void
    {
        Queue::fake();
        $this->connect();
        // 1ページ100件・取り込み上限20件に対し、窓の中に250通ある
        $this->fakeGmailPages(array_map(fn (int $i) => sprintf('msg-%03d', $i), range(1, 250)));

        $result = app(FetchMailAction::class)->execute();

        $this->assertSame(250, $result['found'], 'ページを辿って全件見えている');
        $this->assertSame(20, $result['queued'], 'AI を使う取り込みは上限どおり');
        $this->assertSame(230, $result['pending'], '積まなかった分は黙って捨てず件数で返す');
        Queue::assertPushed(IngestMailJob::class, 20);
    }

    public function test_上限を超えた分は次の取得で続きから進む(): void
    {
        Queue::fake();
        $this->connect();
        $this->fakeGmailPages(array_map(fn (int $i) => sprintf('msg-%03d', $i), range(1, 25)));

        // 1回目で積んだ20通が取り込み済みになった状態を作る
        app(FetchMailAction::class)->execute();
        foreach (range(1, 20) as $i) {
            MailIngest::create([
                'external_id' => sprintf('msg-%03d', $i),
                'from_domain' => 'bizreach.jp',
                'subject' => '取り込み済み',
                'received_at' => now(),
                'mail_kind' => 'scout',
            ]);
        }

        $second = app(FetchMailAction::class)->execute();

        $this->assertSame(5, $second['queued'], '21通目以降へ進める');
        $this->assertSame(0, $second['pending']);
        Queue::assertPushed(fn (IngestMailJob $job) => $job->messageId === 'msg-025');
    }

    public function test_取得すると最終取得日時が更新される(): void
    {
        Queue::fake();
        $credential = $this->connect();
        Http::fake(['gmail.googleapis.com/*' => Http::response(['messages' => []])]);

        app(FetchMailAction::class)->execute();

        $this->assertNotNull($credential->fresh()->last_fetched_at);
    }

    public function test_未連携なら黙って0件にせず例外になる(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('連携されていません');

        app(FetchMailAction::class)->execute();
    }

    public function test_許可ドメイン未設定なら例外になる(): void
    {
        $this->connect();
        MailSetting::query()->update(['allowed_domains' => '']);

        $this->expectException(\RuntimeException::class);
        // 復旧手順を示す文言まで確かめる（設定の置き場が変わったら気づけるようにする）
        $this->expectExceptionMessage('設定画面のGmail連携から登録してください');

        app(FetchMailAction::class)->execute();
    }

    public function test_gmail_apiの失敗は握り潰さない(): void
    {
        Queue::fake();
        $this->connect();
        Http::fake(['gmail.googleapis.com/*' => Http::response(['error' => 'insufficient permissions'], 403)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Gmail API');

        app(FetchMailAction::class)->execute();
    }

    public function test_期限切れのアクセストークンはリフレッシュされる(): void
    {
        Queue::fake();
        $credential = GoogleCredential::create([
            'refresh_token' => 'refresh-1',
            'access_token' => 'expired',
            'access_token_expires_at' => now()->subHour(),
        ]);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh-1', 'expires_in' => 3600]),
            'gmail.googleapis.com/*' => Http::response(['messages' => []]),
        ]);

        app(FetchMailAction::class)->execute();

        $this->assertSame('fresh-1', $credential->fresh()->access_token);
    }

    public function test_コマンドとして実行できる(): void
    {
        Queue::fake();
        $this->connect();
        Http::fake(['gmail.googleapis.com/*' => Http::response(['messages' => [['id' => 'new-1']]])]);

        $this->artisan('mail:fetch')->assertSuccessful();

        Queue::assertPushed(IngestMailJob::class, 1);
    }

    public function test_取得の失敗は連携情報に残り次の成功で消える(): void
    {
        // スケジューラの失敗はログにしか出ないため、画面に出せる場所へ残す
        Queue::fake();
        $credential = $this->connect();
        // 1回目は 403、2回目は成功（fake を重ね掛けすると先に登録した方が勝つため sequence で並べる）
        Http::fake(['gmail.googleapis.com/*' => Http::sequence()
            ->push(['error' => 'insufficient permissions'], 403)
            ->push(['messages' => []])]);

        try {
            app(FetchMailAction::class)->execute();
            $this->fail('失敗は例外として伝播するはず');
        } catch (\RuntimeException) {
            // 期待どおり
        }

        $credential->refresh();
        $this->assertStringContainsString('Gmail API', (string) $credential->last_fetch_error);
        $this->assertNotNull($credential->last_fetch_error_at);

        app(FetchMailAction::class)->execute();

        $this->assertNull($credential->fresh()->last_fetch_error, '成功したら消える');
    }

    public function test_取得の失敗が設定画面に出る(): void
    {
        $this->connect()->update(['last_fetch_error' => 'トークンが失効した', 'last_fetch_error_at' => now()]);

        $this->get(route('settings.mail'))
            ->assertInertia(fn ($page) => $page->where('gmail.lastFetchError', 'トークンが失効した'));
    }
}
