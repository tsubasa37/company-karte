<?php

namespace App\Actions;

use App\Enums\ScheduleKind;
use App\Models\Company;
use App\Models\ScheduleEvent;
use App\Services\ScheduleEventPresenter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * 予定表ページ（リスト / 月カレンダー）の描画データを組み立てる。
 *
 * 「今週か来週か過ぎているか」「カレンダーの何行目の何曜日か」の判定はすべてここで確定し、
 * Vue 側は描画だけを行う（既存の BuildDashboardAction と同じ方針）。
 */
class BuildSchedulePageAction
{
    public function __construct(private readonly ScheduleEventPresenter $presenter) {}

    /**
     * @param  string|null  $month  'YYYY-MM'。未指定なら今月
     * @return array<string, mixed>
     */
    public function execute(?string $month = null): array
    {
        $events = ScheduleEvent::query()->with('company')->chronological()->get();
        $target = $this->targetMonth($month);

        return [
            'groups' => $this->groups($events),
            'calendar' => $this->calendar($target, $events),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name])->all(),
            'kindOptions' => ScheduleKind::options(),
        ];
    }

    /** 不正な month クエリは今月として扱う（表示位置のパラメータであり、失敗させる価値がない） */
    private function targetMonth(?string $month): CarbonImmutable
    {
        if ($month !== null && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        }

        return CarbonImmutable::now()->startOfMonth();
    }

    /**
     * 時系列リスト用の区分。差し迫っている順に並べ、過去だけ新しい順にする。
     * 空の区分は落とす（見出しだけが並ぶのを避ける）。
     *
     * @param  Collection<int, ScheduleEvent>  $events
     * @return array<int, array<string, mixed>>
     */
    private function groups(Collection $events): array
    {
        $todayStart = CarbonImmutable::now()->startOfDay();
        // 週の終わりは土曜。月カレンダー（日曜始まり）と同じ週の区切りにする
        $thisWeekEnd = CarbonImmutable::now()->endOfWeek(CarbonInterface::SATURDAY);
        $nextWeekEnd = CarbonImmutable::now()->addWeek()->endOfWeek(CarbonInterface::SATURDAY);

        return collect([
            ['key' => 'thisWeek', 'label' => '今週', 'events' => $events->filter(
                fn (ScheduleEvent $e) => $e->starts_at >= $todayStart && $e->starts_at <= $thisWeekEnd,
            )],
            ['key' => 'nextWeek', 'label' => '来週', 'events' => $events->filter(
                fn (ScheduleEvent $e) => $e->starts_at > $thisWeekEnd && $e->starts_at <= $nextWeekEnd,
            )],
            ['key' => 'later', 'label' => 'それ以降', 'events' => $events->filter(
                fn (ScheduleEvent $e) => $e->starts_at > $nextWeekEnd,
            )],
            ['key' => 'past', 'label' => '過去', 'events' => $events->filter(
                fn (ScheduleEvent $e) => $e->starts_at < $todayStart,
            )->reverse()],
        ])
            ->map(fn (array $g) => [
                'key' => $g['key'],
                'label' => $g['label'],
                'events' => $g['events']->map($this->presenter->present(...))->values()->all(),
            ])
            ->filter(fn (array $g) => $g['events'] !== [])
            ->values()
            ->all();
    }

    /**
     * 月カレンダー（日曜始まりの7列グリッド）。日付の計算はここで済ませ、Vue は表を描くだけにする。
     *
     * @param  Collection<int, ScheduleEvent>  $events
     * @return array<string, mixed>
     */
    private function calendar(CarbonImmutable $month, Collection $events): array
    {
        $gridStart = $month->startOfWeek(CarbonInterface::SUNDAY);
        $gridEnd = $month->endOfMonth()->endOfWeek(CarbonInterface::SATURDAY);
        $today = CarbonImmutable::now()->format('Y-m-d');

        $byDate = $events
            ->filter(fn (ScheduleEvent $e) => $e->starts_at >= $gridStart && $e->starts_at <= $gridEnd)
            ->groupBy(fn (ScheduleEvent $e) => $e->starts_at->format('Y-m-d'));

        $days = [];
        for ($date = $gridStart; $date <= $gridEnd; $date = $date->addDay()) {
            $key = $date->format('Y-m-d');
            $days[] = [
                'date' => $key,
                'day' => $date->day,
                'isToday' => $key === $today,
                'isCurrentMonth' => $date->month === $month->month,
                'events' => collect($byDate->get($key, []))
                    ->map($this->presenter->present(...))->values()->all(),
            ];
        }

        return [
            'month' => $month->format('Y-m'),
            'label' => $month->format('Y年n月'),
            'prevMonth' => $month->subMonth()->format('Y-m'),
            'nextMonth' => $month->addMonth()->format('Y-m'),
            'weekdays' => ScheduleEventPresenter::WEEKDAYS,
            'weeks' => array_chunk($days, 7),
        ];
    }
}
