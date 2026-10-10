<?php

namespace App\Http\Controllers;

use App\Actions\SaveAiRoutingAction;
use App\Http\Requests\AiRoutingRequest;
use Illuminate\Http\RedirectResponse;

class AiRoutingController extends Controller
{
    /** Tier ごとの実行先を保存する。保存した瞬間から次の実行に効く */
    public function update(AiRoutingRequest $request, SaveAiRoutingAction $action): RedirectResponse
    {
        $action->execute($request->validated());

        // このフォームは AI エンジン設定ページ専用なので、戻り先を明示する
        // （back() は Referer 頼みで、Referer が無い環境では最後に直接開いた画面へ戻ってしまう）
        return redirect()->route('settings.ai');
    }
}
