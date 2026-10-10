<?php

namespace App\Enums;

/**
 * 取り込んだメールの種類（codex が判定する）。取り込み履歴の表示に使う。
 */
enum MailKind: string
{
    case Scout = 'scout';
    case Schedule = 'schedule';
    case Result = 'result';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Scout => 'スカウト',
            self::Schedule => '日程調整',
            self::Result => '選考結果',
            self::Other => 'その他',
        };
    }
}
