<?php

namespace Tests\Feature;

use App\Actions\IngestMailAction;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\MailSetting;
use App\Services\Ai\MailExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * 画面内で操作したあと、操作した画面へ戻ること。
 *
 * 保存後の back() と入力エラーの戻り先は Referer で決まる。Referrer-Policy が no-referrer だと
 * Referer が付かず、Inertia の画面内遷移（XHR）はセッションの「直前の URL」も更新しないため、
 * 最後に直接開いた画面（多くはダッシュボード）へ飛ばされていた。
 * ブラウザは same-origin なら今いる画面を Referer に付ける（実ブラウザで確認済み）。
 * ここではその Referer を付けて送り、戻り先を固定する。
 */
class ReturnToOriginTest extends TestCase
{
    use RefreshDatabase;

    /** いま開いている画面から、画面内の操作として送る（ブラウザが付けるヘッダを再現） */
    private function fromPage(string $path): static
    {
        return $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Referer' => url($path),
        ]);
    }

    public function test_同じサイト内にだけリファラを送る設定になっている(): void
    {
        $this->get('/companies')->assertHeader('Referrer-Policy', 'same-origin');
    }

    public function test_ダッシュボードから移った一覧で星を押しても一覧に戻る(): void
    {
        $company = Company::create(['name' => 'テスト社']);
        $this->get('/')->assertOk(); // 最後に直接開いたのはダッシュボード

        $this->fromPage('/companies')
            ->patch(route('companies.favorite', $company), ['favorite' => true])
            ->assertRedirect(url('/companies'));

        $this->assertTrue($company->fresh()->favorite);
    }

    public function test_基本情報の保存はタブ指定ごと企業ページに戻る(): void
    {
        $company = Company::create(['name' => 'テスト社']);
        $this->get('/')->assertOk();

        $this->fromPage("/companies/{$company->id}?tab=info")
            ->put(route('companies.update', $company), [
                'name' => 'テスト社',
                'status' => CompanyStatus::Interview->value,
            ])
            ->assertRedirect(url("/companies/{$company->id}?tab=info"));
    }

    public function test_面接記録の入力エラーは企業ページに戻って表示される(): void
    {
        Queue::fake();
        $company = Company::create(['name' => 'テスト社']);
        $this->get('/')->assertOk();

        $this->fromPage("/companies/{$company->id}?tab=sources")
            ->post(route('interviews.store', $company), [
                'interviewed_on' => '2026-09-10',
                'round' => '一次面接',
                'interviewer' => str_repeat('あ', 101),
                'answers' => '回答',
            ])
            ->assertRedirect(url("/companies/{$company->id}?tab=sources"))
            ->assertSessionHasErrors('interviewer');
    }

    public function test_メール取り込みの取り消し結果は履歴画面に戻って表示される(): void
    {
        MailSetting::create(['allowed_domains' => 'bizreach.jp']);
        $extractor = Mockery::mock(MailExtractor::class);
        $extractor->shouldReceive('extract')->andReturn([
            'is_job_related' => true,
            'company_name' => '株式会社サンプル',
            'mail_kind' => 'scout',
            'position' => '',
            'event_candidates' => [],
            'summary' => 'スカウトの案内',
        ]);
        $this->app->instance(MailExtractor::class, $extractor);

        $ingest = app(IngestMailAction::class)->execute([
            'id' => 'gmail-msg-1',
            'from' => '採用担当 <scout@bizreach.jp>',
            'from_domain' => 'bizreach.jp',
            'subject' => 'スカウトのご案内',
            'received_at' => Carbon::parse('2026-08-20 09:00'),
            'body' => 'この度はスカウトのご連絡です。',
        ]);
        // 取り込み後に本人が選考メモを書いた（取り消しでは企業を残し、その旨を画面に出す）
        $this->travelTo(now()->addMinute(), fn () => $ingest->company->update(['selection_notes' => '面接メモ']));
        $this->get('/')->assertOk();

        $this->fromPage('/mails')
            ->delete(route('mails.destroy', $ingest))
            ->assertRedirect(url('/mails'))
            ->assertSessionHas('mailRevert');
    }
}
