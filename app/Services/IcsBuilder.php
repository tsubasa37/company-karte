<?php

namespace App\Services;

use App\Models\ScheduleEvent;

/**
 * 予定1件を iCalendar(.ics) テキストに変換する（RFC 5545）。
 *
 * 日時は TZID=Asia/Tokyo のローカル時刻として書き出す。DBの日時は datetime-local 入力の
 * 「日本時間の壁時計」をそのまま保持しており、これを Z 付き UTC として出すと
 * カレンダー上で9時間ずれるため。
 */
class IcsBuilder
{
    private const TZID = 'Asia/Tokyo';

    /** JST は夏時間を持たないため固定オフセットで VTIMEZONE を書ける */
    private const UTC_OFFSET = '+0900';

    /** UID のドメイン部。予定IDに対して不変にし、再取り込みを重複ではなく更新にする */
    private const UID_DOMAIN = 'company-karte.local';

    /** RFC 5545 の1行あたりのオクテット数上限（CRLF を除く） */
    private const LINE_OCTETS = 75;

    public function build(ScheduleEvent $event): string
    {
        $start = $event->starts_at;
        $end = $start->copy()->addMinutes($event->duration_minutes ?? ScheduleEvent::DEFAULT_DURATION_MINUTES);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//company-karte//JP',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            ...$this->timezoneBlock(),
            'BEGIN:VEVENT',
            'UID:'.$this->uid($event),
            // DTSTAMP は UTC 固定。生成時刻であって予定の時刻ではない
            'DTSTAMP:'.now()->format('Ymd\THis\Z'),
            'SEQUENCE:'.$this->sequence($event),
            'DTSTART;TZID='.self::TZID.':'.$start->format('Ymd\THis'),
            'DTEND;TZID='.self::TZID.':'.$end->format('Ymd\THis'),
            'SUMMARY:'.$this->escape($this->summary($event)),
        ];

        if (filled($event->place)) {
            $lines[] = 'LOCATION:'.$this->escape($event->place);
        }
        if (filled($event->note)) {
            $lines[] = 'DESCRIPTION:'.$this->escape($event->note);
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    /** 取り込み側でファイル名に使われる。予定が特定できる形にする */
    public function filename(ScheduleEvent $event): string
    {
        return 'schedule-'.$event->id.'-'.$event->starts_at->format('Ymd-Hi').'.ics';
    }

    /** 予定IDに対して不変。同じ予定を再取り込みしても重複せず上書きになる */
    private function uid(ScheduleEvent $event): string
    {
        return 'schedule-event-'.$event->id.'@'.self::UID_DOMAIN;
    }

    /**
     * 編集のたびに増える値。カレンダー側が「同じUIDの新しい版」と判断できるようにする。
     * 作成からの経過秒を使う（作成直後は 0。SEQUENCE の 32bit 範囲に収まる）。
     */
    private function sequence(ScheduleEvent $event): int
    {
        return max(0, $event->updated_at->getTimestamp() - $event->created_at->getTimestamp());
    }

    private function summary(ScheduleEvent $event): string
    {
        $label = $event->kind->label();
        if (filled($event->title)) {
            $label .= '（'.$event->title.'）';
        }

        return $event->company->name.' '.$label;
    }

    /**
     * JST の VTIMEZONE 定義。これが無いとカレンダーによって TZID を解釈できない。
     *
     * @return array<int, string>
     */
    private function timezoneBlock(): array
    {
        return [
            'BEGIN:VTIMEZONE',
            'TZID:'.self::TZID,
            'BEGIN:STANDARD',
            'DTSTART:19700101T000000',
            'TZOFFSETFROM:'.self::UTC_OFFSET,
            'TZOFFSETTO:'.self::UTC_OFFSET,
            'TZNAME:JST',
            'END:STANDARD',
            'END:VTIMEZONE',
        ];
    }

    /** RFC 5545 の TEXT エスケープ。バックスラッシュを先に処理しないと二重変換になる */
    private function escape(string $text): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n", "\r"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'],
            $text,
        );
    }

    /**
     * 75オクテットを超える行を継続行（先頭に空白1つ）へ折り返す。
     * マルチバイト文字の途中で切らないよう、文字単位で積む。
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= self::LINE_OCTETS) {
            return $line;
        }

        $folded = '';
        $current = '';
        // 継続行は先頭の空白1オクテットを含めて75になるため、積める上限が1つ減る
        $limit = self::LINE_OCTETS;

        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $folded .= $current."\r\n ";
                $current = '';
                $limit = self::LINE_OCTETS - 1;
            }
            $current .= $char;
        }

        return $folded.$current;
    }
}
