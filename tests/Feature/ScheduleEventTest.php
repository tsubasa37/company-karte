<?php

namespace Tests\Feature;

use App\Actions\BuildCompanyPageAction;
use App\Enums\ScheduleKind;
use App\Enums\ScheduleSource;
use App\Models\Company;
use App\Models\ScheduleEvent;
use App\Services\ScheduleEventPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * 選考予定の登録・更新・削除と、予定表ページへの反映。
 * 「1社に複数の面接を並べられる」ことがこの機能の主目的なので、そこを軸に確かめる。
 */
class ScheduleEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_予定を登録できる(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        $this->post(route('schedule.store'), [
            'company_id' => $company->id,
            'kind' => ScheduleKind::Interview->value,
            'title' => '1次',
            'starts_at' => '2026-09-01T10:30',
            'duration_minutes' => 45,
            'place' => 'https://meet.example.com/abc',
            'note' => '面接官: 山田',
        ])->assertRedirect();

        $event = ScheduleEvent::firstOrFail();
        $this->assertSame($company->id, $event->company_id);
        $this->assertSame(ScheduleKind::Interview, $event->kind);
        $this->assertSame('1次', $event->title);
        $this->assertSame('2026-09-01 10:30', $event->starts_at->format('Y-m-d H:i'));
        $this->assertSame(45, $event->duration_minutes);
    }

    public function test_同じ企業に複数の面接予定を並べられる(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        foreach ([['1次', '2026-09-01T10:00'], ['2次', '2026-09-08T15:00']] as [$title, $at]) {
            $this->post(route('schedule.store'), [
                'company_id' => $company->id,
                'kind' => ScheduleKind::Interview->value,
                'title' => $title,
                'starts_at' => $at,
            ])->assertRedirect();
        }

        // 後から入れた2次が1次を上書きしない（元の next_interview_at では消えていた）
        $titles = ScheduleEvent::query()->chronological()->pluck('title')->all();
        $this->assertSame(['1次', '2次'], $titles);
    }

    public function test_開始日時が無いと弾かれる(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        $this->post(route('schedule.store'), [
            'company_id' => $company->id,
            'kind' => ScheduleKind::Interview->value,
        ])->assertSessionHasErrors('starts_at');

        $this->assertSame(0, ScheduleEvent::count());
    }

    public function test_企業を指定しないと弾かれる(): void
    {
        $this->post(route('schedule.store'), [
            'kind' => ScheduleKind::Interview->value,
            'starts_at' => '2026-09-01T10:00',
        ])->assertSessionHasErrors('company_id');

        $this->assertSame(0, ScheduleEvent::count());
    }

    public function test_placeにjavascriptスキームを入れると弾かれる(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        $this->post(route('schedule.store'), [
            'company_id' => $company->id,
            'kind' => ScheduleKind::Interview->value,
            'starts_at' => '2026-09-01T10:00',
            'place' => 'javascript:alert(1)',
        ])->assertSessionHasErrors('place');

        $this->assertSame(0, ScheduleEvent::count());
    }

    public function test_予定を更新できる(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $event = $company->scheduleEvents()->create([
            'kind' => ScheduleKind::Interview,
            'starts_at' => '2026-09-01 10:00',
        ]);

        $this->put(route('schedule.update', $event), [
            'company_id' => $company->id,
            'kind' => ScheduleKind::Casual->value,
            'starts_at' => '2026-09-02T13:00',
            'title' => '再調整',
        ])->assertRedirect();

        $event->refresh();
        $this->assertSame(ScheduleKind::Casual, $event->kind);
        $this->assertSame('2026-09-02 13:00', $event->starts_at->format('Y-m-d H:i'));
        $this->assertSame('再調整', $event->title);
    }

    public function test_予定を削除できる(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $event = $company->scheduleEvents()->create([
            'kind' => ScheduleKind::Interview,
            'starts_at' => '2026-09-01 10:00',
        ]);

        $this->delete(route('schedule.destroy', $event))->assertRedirect();

        $this->assertSame(0, ScheduleEvent::count());
    }

    public function test_企業を削除すると紐づく予定も消える(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $company->scheduleEvents()->create([
            'kind' => ScheduleKind::Interview,
            'starts_at' => '2026-09-01 10:00',
        ]);

        $company->delete();

        $this->assertSame(0, ScheduleEvent::count());
    }

    public function test_予定表に時系列で並ぶ(): void
    {
        // 週の区切りは日曜始まり。実行日が土曜だと「明日」が翌週に入り区分が変わるため、
        // 週の途中（水曜）に時刻を固定して曜日に依存しないようにする
        $this->travelTo('2026-08-19 10:00');

        $company = Company::create(['name' => '株式会社テスト']);
        $company->scheduleEvents()->create([
            'kind' => ScheduleKind::Interview,
            'title' => '2次',
            'starts_at' => now()->addDay()->setTime(15, 0),
        ]);
        $company->scheduleEvents()->create([
            'kind' => ScheduleKind::Casual,
            'starts_at' => now()->addDay()->setTime(10, 0),
        ]);

        $this->get(route('schedule.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Schedule')
                ->where('groups.0.key', 'thisWeek')
                ->where('groups.0.events.0.displayTitle', 'カジュアル面談')
                ->where('groups.0.events.1.displayTitle', '面接（2次）'));
    }

    public function test_httpsの場所だけリンクとして渡される(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $company->scheduleEvents()->create([
            'kind' => ScheduleKind::Interview,
            'starts_at' => now()->addDay()->setTime(10, 0),
            'place' => 'https://meet.example.com/abc',
        ]);
        $company->scheduleEvents()->create([
            'kind' => ScheduleKind::Interview,
            'starts_at' => now()->addDay()->setTime(11, 0),
            'place' => '本社5F 会議室A',
        ]);

        $this->get(route('schedule.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('groups.0.events.0.placeUrl', 'https://meet.example.com/abc')
                ->where('groups.0.events.1.placeUrl', null));
    }

    public function test_企業ページにその企業の予定が渡る(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $other = Company::create(['name' => '別会社']);
        $company->scheduleEvents()->create([
            'kind' => ScheduleKind::Interview,
            'starts_at' => '2026-09-01 10:00',
        ]);
        $other->scheduleEvents()->create([
            'kind' => ScheduleKind::Interview,
            'starts_at' => '2026-09-02 10:00',
        ]);

        $this->get(route('companies.show', $company))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('scheduleEvents', 1)
                ->where('scheduleEvents.0.companyName', '株式会社テスト'));
    }

    public function test_カレンダーは指定した月のグリッドを返す(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $company->scheduleEvents()->create([
            'kind' => ScheduleKind::Interview,
            'starts_at' => '2026-09-10 10:00',
        ]);

        $this->get(route('schedule.index', ['month' => '2026-09']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('calendar.month', '2026-09')
                ->where('calendar.label', '2026年9月')
                ->where('calendar.prevMonth', '2026-08')
                ->where('calendar.nextMonth', '2026-10')
                // 2026-09-01 は火曜。日曜始まりなので先頭週の3列目が1日
                ->where('calendar.weeks.0.2.day', 1)
                ->where('calendar.weeks.0.2.isCurrentMonth', true));
    }

    public function test_sourceを渡さずに作った予定も整形できる(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        // DB の default だけに頼ると、再読込前のインスタンスは source が null のままになる
        $event = $company->scheduleEvents()->create([
            'kind' => ScheduleKind::Interview,
            'starts_at' => '2026-09-01 10:00',
        ]);

        $presented = app(ScheduleEventPresenter::class)->present($event->load('company'));

        $this->assertSame(ScheduleSource::Manual, $event->source);
        $this->assertNull($presented['sourceBadge']);
    }

    public function test_企業ページの予定整形で企業を引き直さない(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        foreach ([1, 2, 3] as $i) {
            $company->scheduleEvents()->create([
                'kind' => ScheduleKind::Interview,
                'starts_at' => "2026-09-0{$i} 10:00",
            ]);
        }

        DB::enableQueryLog();
        app(BuildCompanyPageAction::class)->execute($company->fresh());
        $singleCompanyQueries = collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_contains($q['query'], 'from "companies" where "companies"."id" = ?'))
            ->count();
        DB::disableQueryLog();

        // 予定件数ぶんの企業取得（N+1）を出さない
        $this->assertSame(0, $singleCompanyQueries);
    }

    public function test_monthクエリが配列でも500にならない(): void
    {
        $this->get('/schedule?month[]=2026-09&month[]=x')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('calendar.month', now()->format('Y-m')));
    }
}
