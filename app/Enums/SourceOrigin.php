<?php

namespace App\Enums;

enum SourceOrigin: string
{
    case Paste = 'paste';
    case Url = 'url';
    case Mail = 'mail';

    public function label(): string
    {
        return match ($this) {
            self::Paste => 'ペースト',
            self::Url => 'URL',
            self::Mail => 'メール',
        };
    }
}
