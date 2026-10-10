<?php

namespace App\Http\Controllers;

use App\Actions\TestAiDecisionConnectionAction;
use App\Services\Ai\AiSettings;
use Illuminate\Http\RedirectResponse;

class AiDecisionTestController extends Controller
{
    /** 保存済みの設定で判定エンジンに繋がるかを確かめ、結果を画面に返す（どの実行先の結果かを添える。未設定なら null） */
    public function __invoke(TestAiDecisionConnectionAction $action, AiSettings $settings): RedirectResponse
    {
        $result = $action->execute();

        // 戻り先を明示する理由は AiRoutingController と同じ（back() はダッシュボードへ飛ぶ）
        return redirect()->route('settings.ai')
            ->with('aiDecisionTest', ['provider' => $settings->decisionProvider()?->value, ...$result]);
    }
}
