<?php

namespace App\Enums;

/**
 * 予定がどこから入ったか。メール由来のものは画面でバッジを出し、
 * 自動登録の取り違えに気づけるようにする（承認ゲートではなく事後の見分け）。
 */
enum ScheduleSource: string
{
    case Manual = 'manual';
    case Mail = 'mail';

    public function label(): string
    {
        return match ($this) {
            self::Manual => '手入力',
            self::Mail => 'メールから自動登録',
        };
    }

    /** 画面のバッジ。手入力は由来が自明なのでバッジを出さない */
    public function badge(): ?string
    {
        return match ($this) {
            self::Manual => null,
            self::Mail => '📧 メール',
        };
    }
}
