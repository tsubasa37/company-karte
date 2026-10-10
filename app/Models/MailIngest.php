<?php

namespace App\Models;

use App\Enums\MailKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 取り込んだメール1通の記録。何を作ったかを残し、誤登録を追跡・取り消しできるようにする。
 */
class MailIngest extends Model
{
    protected $fillable = [
        'external_id',
        'from_domain',
        'subject',
        'received_at',
        'mail_kind',
        'summary',
        'company_id',
        'source_document_id',
        'schedule_event_id',
        'created_company',
        'event_candidates',
        'reviewed',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'mail_kind' => MailKind::class,
            'received_at' => 'datetime',
            'event_candidates' => 'array',
            'created_company' => 'boolean',
            'reviewed' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(SourceDocument::class);
    }

    public function scheduleEvent(): BelongsTo
    {
        return $this->belongsTo(ScheduleEvent::class);
    }

    /** 受信の新しい順（履歴画面の既定の並び） */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('received_at')->orderByDesc('id');
    }
}
