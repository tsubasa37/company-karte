<?php

namespace Tests\Feature;

use App\Actions\FetchMailAction;
use App\Actions\IngestMailAction;
use App\Enums\CompanyStatus;
use App\Enums\MetricSource;
use App\Enums\ScheduleKind;
use App\Enums\ScheduleSource;
use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Jobs\IngestMailJob;
use App\Models\Company;
use App\Models\GoogleCredential;
use App\Models\MailIngest;
use App\Models\MailSetting;
use App\Models\OfficialMetric;
use App\Models\ScheduleEvent;
use App\Models\SourceDocument;
use App\Services\Ai\MailExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * メール取り込みの本体。自動登録するぶん「勝手に作りすぎない」ことを重点的に確かめる。
 */
class MailIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MailSetting::create(['allowed_domains' => 'bizreach.jp,example.co.jp']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function fakeExtractor(array $overrides = []): void
    {
        $mock = Mockery::mock(MailExtractor::class);
        $mock->shouldReceive('extract')->andReturn($overrides + [
            'is_job_related' => true,
            'company_name' => '株式会社サンプル',
            'mail_kind' => 'scout',
            'position' => 'バックエンドエンジニア',
            'event_candidates' => [],
            'summary' => 'スカウトの案内',
        ]);

        $this->app->instance(MailExtractor::class, $mock);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function message(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'gmail-msg-1',
            'from' => '採用担当 <scout@bizreach.jp>',
            'from_domain' => 'bizreach.jp',
            'subject' => 'スカウトのご案内',
            'received_at' => Carbon::parse('2026-08-20 09:00'),
            'body' => 'この度はスカウトのご連絡です。',
        ];
    }

    public function test_スカウトメールから企業が自動登録される(): void
    {
        $this->fakeExtractor();

        $ingest = app(IngestMailAction::class)->execute($this->message());

        $this->assertNotNull($ingest);
        $company = Company::firstOrFail();
        $this->assertSame('株式会社サンプル', $company->name);
        $this->assertSame($company->id, $ingest->company_id);
        $this->assertTrue($ingest->created_company);
    }

    public function test_メール本文がスカウトソースとして残る(): void
    {
        $this->fakeExtractor();

        app(IngestMailAction::class)->execute($this->message());

        $source = SourceDocument::firstOrFail();
        $this->assertSame(SourceType::Scout, $source->type);
        $this->assertSame(SourceOrigin::Mail, $source->origin);
        $this->assertStringContainsString('スカウトのご連絡', $source->raw_text);
    }

    public function test_日程調整メールから予定が自動作成される(): void
    {
        $this->fakeExtractor([
            'mail_kind' => 'schedule',
            'event_candidates' => [
                ['starts_at' => '2026-09-01 15:00', 'kind' => 'interview', 'title' => '1次', 'place' => 'https://meet.example.com/x'],
            ],
        ]);

        $ingest = app(IngestMailAction::class)->execute($this->message());

        $event = ScheduleEvent::firstOrFail();
        $this->assertSame($ingest->schedule_event_id, $event->id);
        $this->assertSame(ScheduleKind::Interview, $event->kind);
        $this->assertSame(ScheduleSource::Mail, $event->source);
        $this->assertSame('2026-09-01 15:00', $event->starts_at->format('Y-m-d H:i'));
        $this->assertSame('1次', $event->title);
    }

    public function test_日時候補が複数あるときは予定を作らず候補を残す(): void
    {
        $this->fakeExtractor([
            'mail_kind' => 'schedule',
            'event_candidates' => [
                ['starts_at' => '2026-09-01 15:00', 'kind' => 'interview', 'title' => '', 'place' => ''],
                ['starts_at' => '2026-09-02 10:00', 'kind' => 'interview', 'title' => '', 'place' => ''],
            ],
        ]);

        $ingest = app(IngestMailAction::class)->execute($this->message());

        // どの候補になるかは本人が返信して決まるため、アプリが勝手に1つ選ばない
        $this->assertSame(0, ScheduleEvent::count());
        $this->assertNull($ingest->schedule_event_id);
        $this->assertCount(2, $ingest->event_candidates);
    }

    public function test_日時の形式が壊れていれば予定を作らない(): void
    {
        $this->fakeExtractor([
            'mail_kind' => 'schedule',
            'event_candidates' => [
                ['starts_at' => '来週の火曜日', 'kind' => 'interview', 'title' => '', 'place' => ''],
            ],
        ]);

        $ingest = app(IngestMailAction::class)->execute($this->message());

        $this->assertSame(0, ScheduleEvent::count());
        $this->assertCount(1, $ingest->event_candidates);
    }

    public function test_同じメールを二重に取り込まない(): void
    {
        $this->fakeExtractor();
        $action = app(IngestMailAction::class);

        $this->assertNotNull($action->execute($this->message()));
        $this->assertNull($action->execute($this->message()));

        $this->assertSame(1, MailIngest::count());
        $this->assertSame(1, Company::count());
    }

    public function test_許可ドメイン外の差出人は取り込まれない(): void
    {
        $this->fakeExtractor();

        $result = app(IngestMailAction::class)->execute(
            $this->message(['from_domain' => 'spam.example.net']),
        );

        $this->assertNull($result);
        $this->assertSame(0, MailIngest::count());
        $this->assertSame(0, Company::count());
    }

    public function test_サブドメインは許可ドメインとして扱われる(): void
    {
        $this->fakeExtractor();

        $result = app(IngestMailAction::class)->execute(
            $this->message(['from_domain' => 'scout.bizreach.jp']),
        );

        $this->assertNotNull($result);
    }

    public function test_既存企業に名寄せされて重複作成されない(): void
    {
        $this->fakeExtractor();
        $existing = Company::create(['name' => 'サンプル']);

        $ingest = app(IngestMailAction::class)->execute($this->message());

        $this->assertSame(1, Company::count());
        $this->assertSame($existing->id, $ingest->company_id);
        $this->assertFalse($ingest->created_company);
    }

    public function test_転職と無関係なメールは企業を作らずに記録だけ残す(): void
    {
        $this->fakeExtractor(['is_job_related' => false, 'mail_kind' => 'other']);

        $ingest = app(IngestMailAction::class)->execute($this->message());

        $this->assertSame(0, Company::count());
        $this->assertSame(0, SourceDocument::count());
        // 記録は残す（次回の取得で同じメールに codex を使い直さないため）
        $this->assertNotNull($ingest);
        $this->assertNull($ingest->company_id);
    }

    public function test_取り込み後に書いた選考メモとオファーは取り消しで消さない(): void
    {
        $this->fakeExtractor();
        $ingest = app(IngestMailAction::class)->execute($this->message());
        $company = $ingest->company;

        // 企業ページから選考メモ・オファーを入れる（取り込みが作ったものではない）
        $this->travelTo(now()->addMinute(), fn () => $this->put(route('companies.update', $company), [
            'name' => $company->name,
            'status' => CompanyStatus::Interview->value,
            'selection_notes' => '一次面接: 残業は月20hとの回答',
            'offer_annual_salary' => 720,
        ])->assertSessionDoesntHaveErrors());

        $this->delete(route('mails.destroy', $ingest))->assertRedirect();

        $fresh = Company::find($company->id);
        $this->assertNotNull($fresh, '本人が書いた内容ごと企業を消さない');
        $this->assertSame('一次面接: 残業は月20hとの回答', $fresh->selection_notes);
        $this->assertSame(720, $fresh->offer_json['annual_salary']);
        // 取り込みが作ったソースは消える（残すのは本人が書いたものだけ）
        $this->assertSame(0, SourceDocument::count());
    }

    public function test_残したものは取り消しの結果として画面に出す(): void
    {
        $this->fakeExtractor();
        $ingest = app(IngestMailAction::class)->execute($this->message());

        $this->travelTo(now()->addMinute(), fn () => $this->put(route('companies.update', $ingest->company), [
            'name' => $ingest->company->name,
            'status' => CompanyStatus::Interview->value,
            'selection_notes' => '面接メモ',
        ])->assertSessionDoesntHaveErrors());

        // 黙って一部だけ効いた取り消しにしない
        $this->delete(route('mails.destroy', $ingest))->assertSessionHas('mailRevert');
    }

    public function test_別の企業へ移したソースは取り消しで消さない(): void
    {
        $this->fakeExtractor();
        Queue::fake(); // 付け替えが積む派生ジョブはこのテストの対象外
        $ingest = app(IngestMailAction::class)->execute($this->message());
        $source = $ingest->sourceDocument;
        $other = Company::create(['name' => '別会社']);

        $this->patch(route('sources.update', $source), [
            'type' => $source->type->value,
            'company_id' => $other->id,
        ])->assertRedirect();

        $this->delete(route('mails.destroy', $ingest))->assertRedirect();

        $this->assertNotNull(SourceDocument::find($source->id), '無関係な企業の材料を巻き添えにしない');
        $this->assertSame($other->id, SourceDocument::find($source->id)->company_id);
    }

    public function test_取り込みだけの企業はこれまでどおり取り消しで消える(): void
    {
        $this->fakeExtractor([
            'mail_kind' => 'schedule',
            'event_candidates' => [
                ['starts_at' => '2026-09-01 15:00', 'kind' => 'interview', 'title' => '', 'place' => ''],
            ],
        ]);
        $ingest = app(IngestMailAction::class)->execute($this->message());

        $this->delete(route('mails.destroy', $ingest))->assertRedirect();

        $this->assertSame(0, Company::count());
        $this->assertSame(0, ScheduleEvent::count());
        $this->assertSame(0, SourceDocument::count());
        // 履歴は残す（同じメールが次回の取得で再登録されないため）
        $this->assertSame(1, MailIngest::count());
    }

    public function test_取り消しても既存企業は消さない(): void
    {
        $this->fakeExtractor();
        $existing = Company::create(['name' => 'サンプル']);
        $ingest = app(IngestMailAction::class)->execute($this->message());

        $this->delete(route('mails.destroy', $ingest))->assertRedirect();

        $this->assertNotNull(Company::find($existing->id));
    }

    public function test_取り込み履歴が画面に出る(): void
    {
        $this->fakeExtractor();
        app(IngestMailAction::class)->execute($this->message());

        $this->get(route('mails.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Mails')
                ->where('unreviewedCount', 1)
                ->where('ingests.0.subject', 'スカウトのご案内')
                ->where('ingests.0.companyName', '株式会社サンプル'));
    }

    public function test_確認済みにできる(): void
    {
        $this->fakeExtractor();
        $ingest = app(IngestMailAction::class)->execute($this->message());

        $this->patch(route('mails.review', $ingest), ['reviewed' => true])->assertRedirect();

        $this->assertTrue($ingest->fresh()->reviewed);
    }

    public function test_暦として存在しない日時では予定を作らない(): void
    {
        // codex が月末の計算を誤ると "2026-02-30 25:00" のような値が返る。
        // 書式は合っているため、繰り上げで別の日時の予定が黙って作られてはいけない
        $this->fakeExtractor([
            'mail_kind' => 'schedule',
            'event_candidates' => [
                ['starts_at' => '2026-02-30 25:00', 'kind' => 'interview', 'title' => '', 'place' => ''],
            ],
        ]);

        $ingest = app(IngestMailAction::class)->execute($this->message());

        $this->assertSame(0, ScheduleEvent::count());
        $this->assertNull($ingest->schedule_event_id);
        $this->assertCount(1, $ingest->event_candidates);
    }

    public function test_候補が複数なら1件が壊れていても予定を作らない(): void
    {
        // 絞り込んでから件数を見ると「2件提示のうち1件がパース不能」で残り1件を選んでしまう
        $this->fakeExtractor([
            'mail_kind' => 'schedule',
            'event_candidates' => [
                ['starts_at' => '2026-09-01 15:00', 'kind' => 'interview', 'title' => '', 'place' => ''],
                ['starts_at' => '来週の火曜日', 'kind' => 'interview', 'title' => '', 'place' => ''],
            ],
        ]);

        $ingest = app(IngestMailAction::class)->execute($this->message());

        $this->assertSame(0, ScheduleEvent::count());
        $this->assertCount(2, $ingest->event_candidates);
    }

    public function test_ポジションが未設定の企業にだけ補われる(): void
    {
        $this->fakeExtractor(['position' => 'バックエンドエンジニア']);

        app(IngestMailAction::class)->execute($this->message());

        $this->assertSame('バックエンドエンジニア', Company::firstOrFail()->position);
    }

    public function test_既存のポジションはメールで上書きしない(): void
    {
        $this->fakeExtractor(['position' => 'バックエンドエンジニア']);
        Company::create(['name' => 'サンプル', 'position' => '本人が入力した職種']);

        app(IngestMailAction::class)->execute($this->message());

        $this->assertSame('本人が入力した職種', Company::firstOrFail()->position);
    }

    public function test_取り消しても公的数値が残っている企業は消さない(): void
    {
        $this->fakeExtractor();
        $ingest = app(IngestMailAction::class)->execute($this->message());
        // 本人が別途 EDINET を取得したあとに取り込みを取り消すケース。
        // official_metrics は cascadeOnDelete なので、企業を消すと道連れになる
        $ingest->company->officialMetrics()->create([
            'source' => MetricSource::Edinet,
            'key' => 'average_annual_salary',
            'value' => '6000000',
            'unit' => '円',
        ]);

        $this->delete(route('mails.destroy', $ingest))->assertRedirect();

        $this->assertSame(1, Company::count());
        $this->assertSame(1, OfficialMetric::count());
    }

    public function test_未確認の取り込みがダッシュボードに出る(): void
    {
        $this->fakeExtractor();
        app(IngestMailAction::class)->execute($this->message());

        // 承認ゲートを置かないぶん、/mails を見に行かなくても気づけるようにする
        $this->get('/')
            ->assertInertia(fn ($page) => $page
                ->where('mailUnreviewed.total', 1)
                ->where('mailUnreviewed.items.0.subject', 'スカウトのご案内')
                ->where('mailUnreviewed.items.0.companyName', '株式会社サンプル'));
    }

    public function test_確認済みにするとダッシュボードから消える(): void
    {
        $this->fakeExtractor();
        $ingest = app(IngestMailAction::class)->execute($this->message());
        $this->patch(route('mails.review', $ingest), ['reviewed' => true]);

        $this->get('/')
            ->assertInertia(fn ($page) => $page->where('mailUnreviewed.total', 0));
    }

    public function test_メール由来のソースが貼り付けと表示されない(): void
    {
        $this->fakeExtractor();
        app(IngestMailAction::class)->execute($this->message());

        $this->get(route('sources.show', SourceDocument::firstOrFail()))
            ->assertInertia(fn ($page) => $page->where('source.originLabel', 'メール'));
    }

    public function test_抽出後の登録に失敗したら失敗として記録し例外は握り潰さない(): void
    {
        // 予定の種別が enum 外 → 登録の途中で落ちる（AI は既に1回走っている）
        $this->fakeExtractor([
            'mail_kind' => 'schedule',
            'event_candidates' => [
                ['starts_at' => '2026-09-01 15:00', 'kind' => 'bogus', 'title' => '', 'place' => ''],
            ],
        ]);

        try {
            app(IngestMailAction::class)->execute($this->message());
            $this->fail('登録の失敗は例外として伝播するはず');
        } catch (\ValueError) {
            // 期待どおり
        }

        $ingest = MailIngest::sole();
        $this->assertNotNull($ingest->error, '失敗の理由が履歴に残る');
        $this->assertNull($ingest->company_id, '半端な登録は残らない');
        $this->assertSame(0, Company::count());
        $this->assertSame(0, ScheduleEvent::count());
    }

    public function test_失敗した取り込みは次回の取得で再処理しない(): void
    {
        // 失敗の記録が無いと、15分ごとの取得が同じメールをまた積んで AI を使い直す
        Queue::fake();
        MailIngest::create($this->failedRow('gmail-msg-1'));
        GoogleCredential::create([
            'refresh_token' => 'refresh-1',
            'access_token' => 'access-1',
            'access_token_expires_at' => now()->addHour(),
        ]);
        Http::fake(['gmail.googleapis.com/*' => Http::response(['messages' => [['id' => 'gmail-msg-1']]])]);

        $result = app(FetchMailAction::class)->execute();

        $this->assertSame(0, $result['queued']);
        Queue::assertNothingPushed();
    }

    public function test_失敗した取り込みをやり直すと記録を消して再度キューに積む(): void
    {
        Queue::fake();
        $ingest = MailIngest::create($this->failedRow('gmail-msg-1'));

        $this->post(route('mails.retry', $ingest))->assertRedirect();

        $this->assertNull(MailIngest::find($ingest->id));
        Queue::assertPushed(fn (IngestMailJob $job) => $job->messageId === 'gmail-msg-1');
    }

    public function test_成功した取り込みはやり直せない(): void
    {
        Queue::fake();
        $this->fakeExtractor();
        $ingest = app(IngestMailAction::class)->execute($this->message());

        $this->post(route('mails.retry', $ingest))->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_失敗した取り込みをまとめてやり直せる(): void
    {
        Queue::fake();
        MailIngest::create($this->failedRow('gmail-msg-1'));
        MailIngest::create($this->failedRow('gmail-msg-2'));
        $this->fakeExtractor();
        app(IngestMailAction::class)->execute($this->message(['id' => 'gmail-msg-3']));

        $this->post(route('mails.retry-failed'))->assertRedirect();

        $this->assertSame(1, MailIngest::count(), '成功した記録は残る');
        Queue::assertPushed(IngestMailJob::class, 2);
    }

    public function test_取り込み履歴に失敗の理由と件数が出る(): void
    {
        MailIngest::create($this->failedRow('gmail-msg-1'));

        $this->get(route('mails.index'))
            ->assertInertia(fn ($page) => $page
                ->where('failedCount', 1)
                ->where('ingests.0.failed', true)
                ->where('ingests.0.error', '登録に失敗した')
                ->where('ingests.0.revertible', false));
    }

    /**
     * @return array<string, mixed>
     */
    private function failedRow(string $externalId): array
    {
        return [
            'external_id' => $externalId,
            'from_domain' => 'bizreach.jp',
            'subject' => '失敗したメール',
            'received_at' => now(),
            'mail_kind' => 'other',
            'error' => '登録に失敗した',
        ];
    }
}
