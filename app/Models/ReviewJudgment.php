<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 口コミの機械判定（RJ）の 1 行（ラン × 口コミ × 主張）。確率は内部の集計と並び順にだけ使い、画面に出さない。
 */
class ReviewJudgment extends Model
{
    protected $fillable = [
        'analysis_run_id',
        'review_item_id',
        'claim_index',
        'claim_hash',
        'relation',
        'p_contradict',
        'p_support',
        'p_none',
        'confidence',
        'model',
    ];

    protected function casts(): array
    {
        return [
            'claim_index' => 'integer',
            'p_contradict' => 'float',
            'p_support' => 'float',
            'p_none' => 'float',
            'confidence' => 'float',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AnalysisRun::class, 'analysis_run_id');
    }

    public function reviewItem(): BelongsTo
    {
        return $this->belongsTo(ReviewItem::class);
    }
}
