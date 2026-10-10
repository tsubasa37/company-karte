<?php

namespace App\Actions;

use App\Enums\AiProvider;
use App\Enums\AiTier;
use App\Models\AiProviderConfig;

/**
 * プロバイダごとの設定（モデル・固有オプション）を作成/更新する。
 * API キーはここでは扱わない（置き場所は .env の1本だけ）。
 */
class SaveAiProviderConfigAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(AiProvider $provider, array $data): AiProviderConfig
    {
        $config = AiProviderConfig::firstOrNew(['provider' => $provider->value]);

        $config->fill([
            'model_analysis' => $this->stringOrNull($data['model_analysis'] ?? null),
            'model_light' => $this->stringOrNull($data['model_light'] ?? null),
            'options' => $this->normalizeOptions($data['options'] ?? null),
        ])->save();

        return $config;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return filled($value) ? (string) $value : null;
    }

    /**
     * Tier ごとの固有オプションから未設定（空）の鍵を落とす。
     * 「未設定なら鍵ごと送らない」を成立させるため、空文字を保存しない。全て空なら null。
     *
     * @param  array<string, mixed>|null  $options
     * @return array<string, array<string, mixed>>|null
     */
    private function normalizeOptions(?array $options): ?array
    {
        $normalized = [];
        foreach (AiTier::cases() as $tier) {
            $values = array_filter(
                is_array($options[$tier->value] ?? null) ? $options[$tier->value] : [],
                fn (mixed $value) => filled($value),
            );
            if ($values !== []) {
                $normalized[$tier->value] = $values;
            }
        }

        return $normalized === [] ? null : $normalized;
    }
}
