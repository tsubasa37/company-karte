<?php

namespace App\Actions;

use App\Models\AiSetting;

/**
 * Tier（分析・要約 / 抽出・判定）ごとの実行先を保存する。自分専用アプリなので常に1行を更新する。
 * 保存した瞬間から次の実行に効く（再起動不要）。候補の妥当性は AiRoutingRequest が見る。
 */
class SaveAiRoutingAction
{
    /**
     * @param  array{analysis_provider: string, light_provider: string}  $data
     */
    public function execute(array $data): AiSetting
    {
        $setting = AiSetting::sole();

        $setting->update([
            'analysis_provider' => $data['analysis_provider'],
            'light_provider' => $data['light_provider'],
        ]);

        return $setting;
    }
}
