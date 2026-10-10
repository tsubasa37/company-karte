<?php

namespace App\Models;

use App\Enums\AnalysisStep;
use App\Enums\RunStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnalysisRun extends Model
{
    protected $fillable = [
        'company_id',
        'status',
        'current_step',
        'panel_size',
        'materials_json',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'current_step' => AnalysisStep::class,
            'panel_size' => 'integer',
            // ラン開始時の材料（ID => 更新時刻）。鮮度判定の基準
            'materials_json' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AnalysisStepResult::class);
    }

    /** 複数人で判定したステップの、集計前の生出力（1判定者=1行） */
    public function opinions(): HasMany
    {
        return $this->hasMany(AnalysisOpinion::class);
    }

    /** 口コミの機械判定（RJ）の結果（1行 = 口コミ1件 × 主張1件） */
    public function reviewJudgments(): HasMany
    {
        return $this->hasMany(ReviewJudgment::class);
    }

    /**
     * 未完了（待機中・分析中）のラン。
     * 同じ企業の分析を二重に走らせない判定は、新規実行・再開のどちらもこのスコープで揃える。
     *
     * @param  Builder<AnalysisRun>  $query
     * @return Builder<AnalysisRun>
     */
    public function scopeUnfinished(Builder $query): Builder
    {
        return $query->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value]);
    }

    public function stepFor(AnalysisStep $step): ?AnalysisStepResult
    {
        return $this->steps()->where('step', $step->value)->first();
    }
}
