<?php

namespace App\Models;

use App\Enums\AiProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * AI 実行先の選択（用途の重さ Tier → プロバイダ）。自分専用アプリなので常に1行。
 */
class AiSetting extends Model
{
    protected $fillable = [
        'analysis_provider',
        'light_provider',
        'decision_provider',
        'decision_review_ordering',
        'decision_send_interview_notes',
    ];

    protected function casts(): array
    {
        return [
            'analysis_provider' => AiProvider::class,
            'light_provider' => AiProvider::class,
            // 判定専用の実行先。null は判定エンジンがオフ
            'decision_provider' => AiProvider::class,
            // 裏取り（P2）の口コミを機械判定の関連度順に選ぶか
            'decision_review_ordering' => 'boolean',
            // 面接メモを判定エンジンに送ってよいか（同意スイッチ。既定オフ）
            'decision_send_interview_notes' => 'boolean',
        ];
    }
}
