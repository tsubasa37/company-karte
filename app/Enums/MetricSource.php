<?php

namespace App\Enums;

enum MetricSource: string
{
    case Edinet = 'edinet';
    case Shokuba = 'shokuba';
    case Gbizinfo = 'gbizinfo';

    public function label(): string
    {
        return match ($this) {
            self::Edinet => 'EDINET(有報)',
            self::Shokuba => 'しょくばらぼ',
            self::Gbizinfo => 'gBizINFO',
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
