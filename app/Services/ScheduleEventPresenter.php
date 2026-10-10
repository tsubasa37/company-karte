<?php

namespace App\Services;

use App\Models\ScheduleEvent;
use Carbon\CarbonImmutable;

/**
 * 予定1件を画面用の形に整える。予定表と企業ページで同じ形を使うため、ここに1本化する。
 *
 * 「表示名」「日付ラベル」「場所をリンクにできるか」の判定はすべてここで確定させ、
 * Vue 側では組み立てない（BuildDashboardAction と同じ方針）。
 */
class ScheduleEventPresenter
{
    /** @var array<int, string> */
    public const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

    /** place をリンクとして扱えるスキーム。これ以外はプレーンテキストで表示する */
    private const LINKABLE_SCHEME = '/^https?:\/\//i';

    /**
     * @return array<string, mixed>
     */
    public function present(ScheduleEvent $event): array
    {
        return [
            'id' => $event->id,
            'companyId' => $event->company_id,
            'companyName' => $event->company->name,
            'kind' => $event->kind->value,
            'kindLabel' => $event->kind->label(),
            // メール由来の予定にバッジを出し、自動登録の取り違えに気づけるようにする
            'sourceBadge' => $event->source->badge(),
            'sourceLabel' => $event->source->label(),
            'title' => $event->title,
            'displayTitle' => $this->displayTitle($event),
            // input[type=datetime-local] にそのまま入る形
            'startsAt' => $event->starts_at->format('Y-m-d\TH:i'),
            'date' => $event->starts_at->format('Y-m-d'),
            'time' => $event->starts_at->format('H:i'),
            'dateLabel' => $this->dateLabel($event),
            'durationMinutes' => $event->duration_minutes,
            'place' => $event->place,
            // http/https のときだけリンクにする（javascript: 等を <a> にしない）
            'placeUrl' => $this->placeUrl($event),
            'note' => $event->note,
            'icsUrl' => route('schedule.ics', $event),
            'past' => $event->starts_at < CarbonImmutable::now(),
        ];
    }

    private function displayTitle(ScheduleEvent $event): string
    {
        return filled($event->title)
            ? $event->kind->label().'（'.$event->title.'）'
            : $event->kind->label();
    }

    private function dateLabel(ScheduleEvent $event): string
    {
        $weekday = self::WEEKDAYS[$event->starts_at->dayOfWeek];

        return $event->starts_at->format('n/j').'('.$weekday.') '.$event->starts_at->format('H:i');
    }

    private function placeUrl(ScheduleEvent $event): ?string
    {
        if (blank($event->place) || preg_match(self::LINKABLE_SCHEME, $event->place) !== 1) {
            return null;
        }

        return $event->place;
    }
}
