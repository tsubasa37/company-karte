<?php

namespace App\Http\Controllers;

use App\Models\ScheduleEvent;
use App\Services\IcsBuilder;
use Illuminate\Http\Response;

/**
 * 予定1件を .ics としてダウンロードさせる。
 * カレンダーへの反映はこのボタンを押したときにだけ起きる（外部APIとの自動同期はしない）。
 */
class ScheduleIcsController extends Controller
{
    public function __invoke(ScheduleEvent $event, IcsBuilder $ics): Response
    {
        $event->load('company');

        return response($ics->build($event))
            ->header('Content-Type', 'text/calendar; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$ics->filename($event).'"');
    }
}
