<?php

namespace App\Models;

use App\Enums\ScheduleKind;
use App\Enums\ScheduleSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleEvent extends Model
{
    /** 予定の所要時間が未設定のときに使う既定値（.ics の終了時刻の算出に使う） */
    public const DEFAULT_DURATION_MINUTES = 60;

    /**
     * source を渡さずに作られた予定を「手入力」として扱う。
     * DB の default だけだと、作成直後のインスタンスは source が null のままになり、
     * 再読込せずに画面用データへ整形すると ScheduleSource のメソッド呼び出しで落ちる。
     */
    protected $attributes = ['source' => ScheduleSource::Manual->value];

    protected $fillable = [
        'company_id',
        'source',
        'kind',
        'title',
        'starts_at',
        'duration_minutes',
        'place',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ScheduleKind::class,
            'source' => ScheduleSource::class,
            'starts_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** 時系列（古い順）。予定表・企業ページの並びはこれに揃える */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('starts_at')->orderBy('id');
    }
}
