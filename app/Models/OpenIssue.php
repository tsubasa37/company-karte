<?php

namespace App\Models;

use App\Enums\IssueStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 面接で確認すべき論点（P2 の矛盾・判定割れ・検証不能から追跡対象にしたもの）。
 *
 * 再分析のたびに AI が作り直す P2 判定と違い、こちらは登録時の文言で固定して残す。
 * 「この論点は面接で解消したか」を選考が終わるまで持ち越すのが目的。
 */
class OpenIssue extends Model
{
    protected $fillable = [
        'company_id',
        'opened_run_id',
        'claim',
        'claim_hash',
        'verdict',
        'rationale',
        'status',
        'resolution_note',
        'resolved_source_document_id',
        'matched_at',
        'status_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => IssueStatus::class,
            'matched_at' => 'datetime',
            'status_updated_at' => 'datetime',
        ];
    }

    /**
     * 論点の同一性を判定するキー。
     * 空白と記号の揺れだけを吸収する（文意の同一判定はしない。AI に同定させると
     * ラン間で結果が揺れ、追跡していた論点が別物にすり替わるため）。
     */
    public static function hashFor(string $claim): string
    {
        $normalized = preg_replace('/\s+/u', '', $claim) ?? $claim;

        return hash('sha256', $normalized);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** 状態を決めた根拠の面接メモ（手動で状態を変えたときは null） */
    public function resolvedSource(): BelongsTo
    {
        return $this->belongsTo(SourceDocument::class, 'resolved_source_document_id');
    }

    /** まだ面接で確認すべき論点（未確認・未解消・懸念が強まった） */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            fn (IssueStatus $s) => $s->value,
            array_filter(IssueStatus::cases(), fn (IssueStatus $s) => $s->isPending()),
        ));
    }
}
