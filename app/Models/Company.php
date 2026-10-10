<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Enums\Recommendation;
use App\Enums\RunStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Company extends Model
{
    protected $fillable = [
        'name',
        'legal_number',
        'listed',
        'position',
        'favorite',
        'status',
        'recommendation',
        'selection_notes',
        'offer_deadline',
        'briefing_json',
        'briefing_requested_at',
        'briefing_generated_at',
        'briefing_error',
        'conditions_json',
        'offer_json',
        'edinet_code',
        'edinet_requested_at',
        'edinet_fetched_at',
        'edinet_error',
        'labor_note',
        'labor_note_history',
        'labor_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'listed' => 'boolean',
            'favorite' => 'boolean',
            'status' => CompanyStatus::class,
            'recommendation' => Recommendation::class,
            // オファー回答期限（1社1つのオファーの属性。面接日程は schedule_events）
            'offer_deadline' => 'date',
            'briefing_json' => 'array',
            'briefing_requested_at' => 'datetime',
            'briefing_generated_at' => 'datetime',
            'conditions_json' => 'array',
            'offer_json' => 'array',
            'edinet_requested_at' => 'datetime',
            'edinet_fetched_at' => 'datetime',
            'labor_note_history' => 'array',
            'labor_checked_at' => 'datetime',
        ];
    }

    public function sourceDocuments(): HasMany
    {
        return $this->hasMany(SourceDocument::class);
    }

    public function officialMetrics(): HasMany
    {
        return $this->hasMany(OfficialMetric::class);
    }

    public function reviewItems(): HasMany
    {
        return $this->hasMany(ReviewItem::class);
    }

    /** 選考の予定（カジュアル面談・面接）。1社に複数持つ */
    public function scheduleEvents(): HasMany
    {
        return $this->hasMany(ScheduleEvent::class);
    }

    /** 面接で確認すべき論点（P2 の矛盾・判定割れ・検証不能から追跡対象にしたもの） */
    public function openIssues(): HasMany
    {
        return $this->hasMany(OpenIssue::class);
    }

    /** ニュースの機械判定の結果（記事の正規化 URL の sha256 で引く） */
    public function newsScreenings(): HasMany
    {
        return $this->hasMany(NewsScreening::class);
    }

    public function analysisRuns(): HasMany
    {
        return $this->hasMany(AnalysisRun::class);
    }

    /** 最新の分析ラン（カルテ描画の対象）。一覧系では with() で先読みして N+1 を出さない */
    public function latestRun(): HasOne
    {
        return $this->hasOne(AnalysisRun::class)->latestOfMany();
    }

    /** 最後に完了(done)した分析ラン（再分析候補の判定基準） */
    public function latestDoneRun(): HasOne
    {
        return $this->hasOne(AnalysisRun::class)->ofMany(
            ['id' => 'MAX'],
            fn (Builder $q) => $q->where('status', RunStatus::Done),
        );
    }
}
