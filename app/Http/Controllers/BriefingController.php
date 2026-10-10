<?php

namespace App\Http\Controllers;

use App\Enums\RunStatus;
use App\Jobs\GenerateBriefingJob;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;

class BriefingController extends Controller
{
    public function store(Company $company): RedirectResponse
    {
        // 生成中の再要求は弾く（codex の二重起動と「生成中」表示の伸びを防ぐ）
        if ($company->briefing_requested_at !== null) {
            return back()->withErrors(['briefing' => 'ブリーフィングを生成中です。完了までお待ちください。']);
        }

        $hasDoneRun = $company->analysisRuns()
            ->where('status', RunStatus::Done)
            ->exists();

        if (! $hasDoneRun) {
            return back()->withErrors(['briefing' => 'ブリーフィングは分析（カルテ）の完了後に生成できます。']);
        }

        $company->update(['briefing_requested_at' => now(), 'briefing_error' => null]);
        GenerateBriefingJob::dispatch($company->id);

        return back();
    }
}
