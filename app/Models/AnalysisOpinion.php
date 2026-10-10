<?php

namespace App\Models;

use App\Enums\AnalysisStep;
use App\Enums\VerdictPerspective;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 複数人で判定したステップの、判定者1人ぶんの生出力（集計前）。
 * 画面に出るのは analysis_steps の集計結果で、こちらは
 * 「どの観点の判定者が何と言ったか」を後から検証・再集計するために残す。
 */
class AnalysisOpinion extends Model
{
    protected $fillable = [
        'analysis_run_id',
        'step',
        'perspective',
        'panelist_index',
        'output_json',
    ];

    protected function casts(): array
    {
        return [
            'step' => AnalysisStep::class,
            'perspective' => VerdictPerspective::class,
            'panelist_index' => 'integer',
            'output_json' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AnalysisRun::class, 'analysis_run_id');
    }
}
