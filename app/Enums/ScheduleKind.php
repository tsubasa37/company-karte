<?php

namespace App\Enums;

enum ScheduleKind: string
{
    case Casual = 'casual';
    case Interview = 'interview';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Casual => 'カジュアル面談',
            self::Interview => '面接',
            self::Other => 'その他',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases(),
        );
    }
}
