<?php

namespace App\Actions;

use App\Enums\AiProvider;
use App\Models\AiProviderConfig;
use App\Models\AiSetting;

/**
 * 判定エンジン（判定専用の実行先）と判定用モデルを保存する。provider が null なら機能オフ。
 * 候補の妥当性は AiDecisionRequest が見る。
 */
class SaveAiDecisionAction
{
    /**
     * @param  array{provider?: string|null, model?: string|null, review_ordering?: bool, send_interview_notes?: bool}  $data
     */
    public function execute(array $data): AiSetting
    {
        $provider = filled($data['provider'] ?? null) ? AiProvider::from($data['provider']) : null;

        $setting = AiSetting::sole();
        $setting->update([
            'decision_provider' => $provider,
            // 判定エンジンがオフなら材料選抜も使えないので戻す（オンのまま残すと再び有効にした瞬間に黙って効く）
            'decision_review_ordering' => $provider !== null && (bool) ($data['review_ordering'] ?? false),
            // 同意スイッチも同じ理由で戻す（オンのまま残すと、判定エンジンを再び有効にした瞬間に面接メモが送られる）
            'decision_send_interview_notes' => $provider !== null && (bool) ($data['send_interview_notes'] ?? false),
        ]);

        // オフにしてもモデルは消さない（再び有効にするときに入れ直さずに済むように）
        if ($provider !== null) {
            $config = AiProviderConfig::firstOrNew(['provider' => $provider->value]);
            $config->fill(['model_decision' => (string) $data['model']])->save();
        }

        return $setting;
    }
}
