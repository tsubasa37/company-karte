<?php

namespace App\Http\Controllers;

use App\Actions\TestAiConnectionAction;
use App\Enums\AiKind;
use App\Enums\AiProvider;
use Illuminate\Http\RedirectResponse;

class AiConnectionTestController extends Controller
{
    /** 保存済みの設定でその実行先に繋がるかを確かめ、結果を画面に返す（どのプロバイダの結果かを添える） */
    public function __invoke(AiProvider $provider, TestAiConnectionAction $action): RedirectResponse
    {
        // 判定専用の実行先はこの経路では扱わない（専用の経路はレイヤー B2）
        if ($provider->kind() !== AiKind::Generation) {
            abort(404);
        }

        $result = $action->execute($provider);

        // 戻り先を明示する理由は AiRoutingController と同じ（back() はダッシュボードへ飛ぶ）
        return redirect()->route('settings.ai')->with('aiTest', ['provider' => $provider->value, ...$result]);
    }
}
