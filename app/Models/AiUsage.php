<?php

namespace App\Models;

use App\Enums\AiProvider;
use App\Enums\AiPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI 実行1回ぶんの記録（計測専用・プロバイダ横断）。プロンプト・出力の本文は持たない。
 * トークン・費用列は取れなかった実行では null（0 と区別する）。
 */
class AiUsage extends Model
{
    protected $fillable = [
        'provider',
        'purpose',
        'model',
        'detail',
        'company_id',
        'duration_ms',
        'prompt_chars',
        'output_chars',
        'input_tokens',
        'cached_input_tokens',
        'cache_write_input_tokens',
        'output_tokens',
        'reasoning_tokens',
        'cost_micro_usd',
        'usage_raw',
        'succeeded',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'provider' => AiProvider::class,
            'purpose' => AiPurpose::class,
            'usage_raw' => 'array',
            'succeeded' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
