<?php

namespace App\Http\Controllers;

use App\Actions\SaveAiProviderConfigAction;
use App\Enums\AiKind;
use App\Enums\AiProvider;
use App\Http\Requests\AiProviderConfigRequest;
use Illuminate\Http\RedirectResponse;

class AiProviderConfigController extends Controller
{
    /** プロバイダごとのモデル・固有オプションを保存する（API キーは扱わない。置き場所は .env の1本だけ） */
    public function update(AiProvider $provider, AiProviderConfigRequest $request, SaveAiProviderConfigAction $action): RedirectResponse
    {
        // 判定専用の実行先はこの経路では扱わない（専用の経路はレイヤー B2）
        if ($provider->kind() !== AiKind::Generation) {
            abort(404);
        }

        $action->execute($provider, $request->validated());

        // 戻り先を明示する理由は AiRoutingController と同じ（back() はダッシュボードへ飛ぶ）
        return redirect()->route('settings.ai');
    }
}
