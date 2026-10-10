<?php

namespace App\Models;

use App\Enums\MetricSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfficialMetric extends Model
{
    protected $fillable = [
        'company_id',
        'source',
        'key',
        'value',
        'unit',
        'fiscal_year',
        'source_url',
    ];

    protected function casts(): array
    {
        return [
            'source' => MetricSource::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
