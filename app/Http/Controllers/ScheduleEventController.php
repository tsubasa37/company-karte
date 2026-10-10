<?php

namespace App\Http\Controllers;

use App\Actions\BuildSchedulePageAction;
use App\Actions\StoreScheduleEventAction;
use App\Actions\UpdateScheduleEventAction;
use App\Http\Requests\ScheduleEventRequest;
use App\Models\ScheduleEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ScheduleEventController extends Controller
{
    public function index(Request $request, BuildSchedulePageAction $action): Response
    {
        // ?month=YYYY-MM でカレンダーの表示月を URL に載せる（リロードで月を失わない）。
        // ?month[]=... のように配列で来ることもあるため、文字列以外は未指定として扱う
        $month = $request->query('month');

        return Inertia::render('Schedule', $action->execute(is_string($month) ? $month : null));
    }

    public function store(ScheduleEventRequest $request, StoreScheduleEventAction $action): RedirectResponse
    {
        $action->execute($request->validated());

        return back();
    }

    public function update(ScheduleEventRequest $request, ScheduleEvent $event, UpdateScheduleEventAction $action): RedirectResponse
    {
        $action->execute($event, $request->validated());

        return back();
    }

    public function destroy(ScheduleEvent $event): RedirectResponse
    {
        $event->delete();

        return back();
    }
}
