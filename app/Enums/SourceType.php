<?php

namespace App\Enums;

enum SourceType: string
{
    case JobPosting = 'jobposting';
    case Review = 'review';
    case Shokuba = 'shokuba';
    case Edinet = 'edinet';
    case News = 'news';
    case Interview = 'interview';
    case Scout = 'scout';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::JobPosting => '求人票',
            self::Review => '口コミ',
            self::Shokuba => 'しょくばらぼ',
            self::Edinet => 'EDINET(有報)',
            self::News => 'ニュース',
            self::Interview => '面接メモ',
            self::Scout => 'スカウトメール',
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
