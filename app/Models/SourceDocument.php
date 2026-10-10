<?php

namespace App\Models;

use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceDocument extends Model
{
    protected $fillable = [
        'company_id',
        'type',
        'origin',
        'url',
        'title',
        'summary',
        'raw_text',
        'fetched_at',
        'split_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => SourceType::class,
            'origin' => SourceOrigin::class,
            'fetched_at' => 'datetime',
            'split_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reviewItems(): HasMany
    {
        return $this->hasMany(ReviewItem::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(SourceRevision::class);
    }
}
