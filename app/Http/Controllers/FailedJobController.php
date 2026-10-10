<?php

namespace App\Http\Controllers;

use App\Actions\ForgetFailedJobAction;
use Illuminate\Http\RedirectResponse;

class FailedJobController extends Controller
{
    /** 対応済みの失敗ジョブをダッシュボードの⛔一覧から取り除く */
    public function destroy(int $id, ForgetFailedJobAction $action): RedirectResponse
    {
        $action->execute($id);

        return back();
    }
}
