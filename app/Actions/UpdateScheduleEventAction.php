<?php

namespace App\Actions;

use App\Models\ScheduleEvent;

/**
 * 予定の内容を書き換える。
 * .ics の SEQUENCE は updated_at から導出するため、更新するとカレンダー側も追随できる。
 */
class UpdateScheduleEventAction
{
    /**
     * @param  array<string, mixed>  $data  company_id/kind/title/starts_at/duration_minutes/place/note
     */
    public function execute(ScheduleEvent $event, array $data): ScheduleEvent
    {
        $event->update($data);

        return $event;
    }
}
