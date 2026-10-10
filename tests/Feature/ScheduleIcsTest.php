<?php

namespace Tests\Feature;

use App\Enums\ScheduleKind;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ics ダウンロード。カレンダーへの反映は「押したときだけ」なので、
 * 外部カレンダーへ自動送信する経路が無いことを前提にレスポンスだけを確かめる。
 */
class ScheduleIcsTest extends TestCase
{
    use RefreshDatabase;

    public function test_予定のicsをダウンロードできる(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $event = $company->scheduleEvents()->create([
            'kind' => ScheduleKind::Interview,
            'title' => '最終',
            'starts_at' => '2026-09-01 10:30',
        ]);

        $response = $this->get(route('schedule.ics', $event));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/calendar; charset=UTF-8');
        $this->assertStringContainsString(
            'attachment; filename="schedule-'.$event->id.'-20260901-1030.ics"',
            $response->headers->get('Content-Disposition'),
        );

        $body = $response->getContent();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $body);
        $this->assertStringContainsString('DTSTART;TZID=Asia/Tokyo:20260901T103000', $body);
        $this->assertStringContainsString('SUMMARY:株式会社テスト 面接（最終）', $body);
    }

    public function test_icsの_ui_dが予定ごとに不変である(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $event = $company->scheduleEvents()->create([
            'kind' => ScheduleKind::Interview,
            'starts_at' => '2026-09-01 10:30',
        ]);

        $first = $this->get(route('schedule.ics', $event))->getContent();
        $second = $this->get(route('schedule.ics', $event))->getContent();

        $uid = 'UID:schedule-event-'.$event->id.'@company-karte.local';
        $this->assertStringContainsString($uid, $first);
        $this->assertStringContainsString($uid, $second);
    }

    public function test_存在しない予定のicsは404になる(): void
    {
        $this->get('/schedule/999/ics')->assertNotFound();
    }
}
