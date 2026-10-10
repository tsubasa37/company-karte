<?php

namespace App\Http\Controllers;

use App\Actions\SaveAiDecisionAction;
use App\Http\Requests\AiDecisionRequest;
use Illuminate\Http\RedirectResponse;

class AiDecisionController extends Controller
{
    /** 判定エンジンと判定用モデルを保存する。空で保存すると機能オフ */
    public function update(AiDecisionRequest $request, SaveAiDecisionAction $action): RedirectResponse
    {
        $action->execute($request->validated());

        // 戻り先を明示する理由は AiRoutingController と同じ（back() はダッシュボードへ飛ぶ）
        return redirect()->route('settings.ai');
    }
}
