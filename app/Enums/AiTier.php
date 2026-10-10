<?php

namespace App\Enums;

/**
 * AI 実行の用途の重さ。呼び出し元はモデル名ではなくこれだけを渡し、実行先は ai_settings で Tier ごとに選ぶ。
 */
enum AiTier: string
{
    case Analysis = 'analysis';
    case Light = 'light';

    public function label(): string
    {
        return match ($this) {
            self::Analysis => '分析・要約',
            self::Light => '抽出・判定',
        };
    }
}
