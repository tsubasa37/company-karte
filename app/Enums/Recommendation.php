<?php

namespace App\Enums;

enum Recommendation: string
{
    case High = 'high';
    case Mid = 'mid';
    case Low = 'low';
    case Hold = 'hold';

    public function symbol(): string
    {
        return match ($this) {
            self::High => '◎',
            self::Mid => '○',
            self::Low => '△',
            self::Hold => '―',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::High => '◎ 前向き検討',
            self::Mid => '○ 条件の確認が必要',
            self::Low => '△ 懸念が大きい',
            self::Hold => '― 判定保留（材料不足）',
        };
    }
}
