<?php

namespace Tests\Unit;

use App\Enums\ScheduleKind;
use App\Models\Company;
use App\Models\ScheduleEvent;
use App\Services\IcsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ics の生成（RFC 5545）。
 * 特に「日本時間として出ること」は、UTC で出すとカレンダー上で9時間ずれる回帰なので必ず見る。
 */
class IcsBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function event(array $attributes = []): ScheduleEvent
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $event = $company->scheduleEvents()->create($attributes + [
            'kind' => ScheduleKind::Interview,
            'starts_at' => '2026-09-01 10:30',
        ]);

        return $event->load('company');
    }

    public function test_日時が_asia_tokyoのローカル時刻で出力される(): void
    {
        $ics = app(IcsBuilder::class)->build($this->event());

        $this->assertStringContainsString('DTSTART;TZID=Asia/Tokyo:20260901T103000', $ics);
        $this->assertStringContainsString('TZID:Asia/Tokyo', $ics);
        // Z 付き UTC で出すと取り込み時に9時間ずれる
        $this->assertStringNotContainsString('DTSTART:20260901T103000Z', $ics);
    }

    public function test_duration未設定なら60分になる(): void
    {
        $ics = app(IcsBuilder::class)->build($this->event(['duration_minutes' => null]));

        $this->assertStringContainsString('DTEND;TZID=Asia/Tokyo:20260901T113000', $ics);
    }

    public function test_duration指定が終了時刻に反映される(): void
    {
        $ics = app(IcsBuilder::class)->build($this->event(['duration_minutes' => 45]));

        $this->assertStringContainsString('DTEND;TZID=Asia/Tokyo:20260901T111500', $ics);
    }

    public function test_ui_dが予定_i_dに対して不変(): void
    {
        $event = $this->event();
        $builder = app(IcsBuilder::class);

        $this->assertStringContainsString(
            'UID:schedule-event-'.$event->id.'@company-karte.local',
            $builder->build($event),
        );
        // 2回目も同じ UID（カレンダー側で重複せず上書きになる）
        $this->assertSame($builder->build($event), $builder->build($event->fresh()->load('company')));
    }

    public function test_改行とカンマとセミコロンがエスケープされる(): void
    {
        $ics = app(IcsBuilder::class)->build($this->event([
            'note' => "1行目,カンマ;セミコロン\n2行目\\バックスラッシュ",
        ]));

        // 折り返し（CRLF+空白）を戻してから中身を見る。エスケープと折り返しは別の関心事
        $unfolded = str_replace("\r\n ", '', $ics);
        $this->assertStringContainsString('1行目\\,カンマ\\;セミコロン\\n2行目\\\\バックスラッシュ', $unfolded);
    }

    public function test_件名に企業名と種別が入る(): void
    {
        $ics = app(IcsBuilder::class)->build($this->event(['title' => '2次']));

        $this->assertStringContainsString('SUMMARY:株式会社テスト 面接（2次）', $ics);
    }

    public function test_長い行が75オクテットで折り返される(): void
    {
        $ics = app(IcsBuilder::class)->build($this->event(['note' => str_repeat('a', 200)]));

        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), "75オクテットを超える行: {$line}");
        }
        // 継続行は先頭が空白1つ
        $this->assertStringContainsString("\r\n ", $ics);
    }

    public function test_全ての行が_crl_fで区切られる(): void
    {
        $ics = app(IcsBuilder::class)->build($this->event());

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        // CR を伴わない LF が混ざっていない
        $this->assertSame(substr_count($ics, "\r\n"), substr_count($ics, "\n"));
    }
}
