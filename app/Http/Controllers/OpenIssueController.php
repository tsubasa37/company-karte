<?php

namespace App\Http\Controllers;

use App\Actions\TrackOpenIssueAction;
use App\Actions\UpdateOpenIssueStatusAction;
use App\Enums\IssueStatus;
use App\Models\AnalysisRun;
use App\Models\Company;
use App\Models\OpenIssue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OpenIssueController extends Controller
{
    /** 手入力メモの長さ上限（面接での回答の要約を想定） */
    private const MAX_NOTE_LENGTH = 2000;

    public function store(Request $request, Company $company, TrackOpenIssueAction $action): RedirectResponse
    {
        $data = $request->validate([
            'run_id' => ['required', 'integer'],
            // P2 出力の verdicts 配列の添字。本文そのものは画面から受け取らない
            'verdict_index' => ['required', 'integer', 'min:0'],
        ]);

        $run = AnalysisRun::findOrFail($data['run_id']);
        $action->execute($company, $run, $data['verdict_index']);

        return back();
    }

    /** 状態の手動変更（面接メモを介さず自分で解消・取り下げにする経路） */
    public function update(Request $request, OpenIssue $issue, UpdateOpenIssueStatusAction $action): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(IssueStatus::class)],
            'resolution_note' => ['nullable', 'string', 'max:'.self::MAX_NOTE_LENGTH],
        ]);

        // メモを送らない（状態だけ変える）ときは、面接メモ由来の根拠と出典を消さない
        $action->execute(
            $issue,
            IssueStatus::from($data['status']),
            $data['resolution_note'] ?? null,
            $request->has('resolution_note'),
        );

        return back();
    }

    public function destroy(OpenIssue $issue): RedirectResponse
    {
        $issue->delete();

        return back();
    }
}
