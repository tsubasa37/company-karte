<?php

namespace App\Models;

use App\Enums\AiProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * プロバイダごとの設定（モデル・固有オプション）。プロバイダにつき1行。
 *
 * API キーはここには持たない（置き場所は .env の1本だけ。読み取りは App\Services\Ai\ApiKeys）。
 */
class AiProviderConfig extends Model
{
    protected $fillable = [
        'provider',
        'model_analysis',
        'model_light',
        'model_decision',
        'options',
    ];

    protected function casts(): array
    {
        return [
            'provider' => AiProvider::class,
            'options' => 'array',
        ];
    }
}
