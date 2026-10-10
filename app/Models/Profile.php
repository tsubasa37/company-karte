<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Profile extends Model
{
    protected $fillable = [
        'career_summary',
        'must_json',
        'want_json',
        'ng_json',
        'axis',
        'current_annual_salary',
        'analysis_relevant_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'must_json' => 'array',
            'want_json' => 'array',
            'ng_json' => 'array',
            'current_annual_salary' => 'integer',
            // 分析に効く項目が最後に変わった時刻（再分析候補の判定基準）
            'analysis_relevant_updated_at' => 'datetime',
        ];
    }
}
