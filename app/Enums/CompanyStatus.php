<?php

namespace App\Enums;

enum CompanyStatus: string
{
    case Kininaru = 'kininaru';
    case Casual = 'casual';
    case Applied = 'applied';
    case Interview = 'interview';
    case Offer = 'offer';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Kininaru => '気になる',
            self::Casual => 'カジュアル面談',
            self::Applied => '応募',
            self::Interview => '面接',
            self::Offer => 'オファー',
            self::Declined => '見送り',
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
