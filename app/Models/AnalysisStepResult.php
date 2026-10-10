<?php

namespace App\Models;

use App\Enums\AnalysisStep;
use App\Enums\StepStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * analysis_steps テーブルの1行。分析パイプラインの各ステップ(P1..P6)の実行結果を保持する。
 * （Enum App\Enums\AnalysisStep との名前衝突を避けるためモデル名は AnalysisStepResult）
 */
class AnalysisStepResult extends Model
{
    protected $table = 'analysis_steps';

    protected $fillable = [
        'analysis_run_id',
        'step',
        'status',
        'output_json',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'step' => AnalysisStep::class,
            'status' => StepStatus::class,
            'output_json' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AnalysisRun::class, 'analysis_run_id');
    }
}
