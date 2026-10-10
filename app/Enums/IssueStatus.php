<?php

namespace App\Enums;

/**
 * 未解決論点の状態。「面接で確認したか」を追跡するための値で、
 * 企業の良し悪しを表す値ではない（点数化・断定はしない）。
 */
enum IssueStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Unresolved = 'unresolved';
    case Worsened = 'worsened';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Open => '未確認',
            self::Resolved => '解消',
            self::Unresolved => '未解消',
            self::Worsened => '懸念が強まった',
            self::Dismissed => '追跡をやめた',
        };
    }

    /**
     * まだ面接で確認すべき論点か。
     * 逆質問（P6）・ブリーフィングに渡す対象と、ダッシュボードの件数はこれで絞る。
     */
    public function isPending(): bool
    {
        return match ($this) {
            self::Open, self::Unresolved, self::Worsened => true,
            self::Resolved, self::Dismissed => false,
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $s) => ['value' => $s->value, 'label' => $s->label()],
            self::cases(),
        );
    }
}
