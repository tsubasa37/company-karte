<?php

namespace App\Models;

use App\Enums\NewsKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ニュースの機械判定の 1 行（企業 × 記事）。確率・score は画面に出さず、しきい値での確定にだけ使う。
 */
class NewsScreening extends Model
{
    protected $fillable = [
        'company_id',
        'url_hash',
        'url',
        'title',
        'source',
        'published_at',
        'relevant_p',
        'kind',
        'severity_score',
        'model',
        'judged_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'date',
            'relevant_p' => 'float',
            'kind' => NewsKind::class,
            'severity_score' => 'float',
            'judged_at' => 'datetime',
        ];
    }

    /** url_hash の値（UrlNormalizer で正規化した URL を渡す） */
    public static function hashOf(string $normalizedUrl): string
    {
        return hash('sha256', $normalizedUrl);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
