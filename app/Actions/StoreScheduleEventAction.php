<?php

namespace App\Actions;

use App\Models\ScheduleEvent;

/**
 * 選考の予定を1件登録する。
 * 1社に何件でも積めるため、既存の予定を上書きしない（1次・2次・最終が並ぶ）。
 */
class StoreScheduleEventAction
{
    /**
     * @param  array<string, mixed>  $data  company_id/kind/title/starts_at/duration_minutes/place/note
     */
    public function execute(array $data): ScheduleEvent
    {
        return ScheduleEvent::create($data);
    }
}
