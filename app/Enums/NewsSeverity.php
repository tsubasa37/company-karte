<?php

namespace App\Enums;

/**
 * 転職先として見たときのニュースの深刻度。宣言順が低い順で、判定の score の段階の並びに使う。
 * 保存はせず（保存するのは score）、しきい値で PHP が確定して画面に返す。
 */
enum NewsSeverity: string
{
    case Neutral = 'neutral';
    case Caution = 'caution';
    case Serious = 'serious';

    public function label(): string
    {
        return match ($this) {
            self::Neutral => '中立',
            self::Caution => '注意',
            self::Serious => '深刻',
        };
    }

    /** 判定の score の段階名 */
    public function level(): string
    {
        return match ($this) {
            self::Neutral => '中立（事実の報道・好材料）',
            self::Caution => '注意（懸念が読み取れる）',
            self::Serious => '深刻（不祥事・大幅な業績悪化・大量退職など）',
        };
    }

    /** 並べ替えの重さ（深刻ほど大きい） */
    public function rank(): int
    {
        return (int) array_search($this, self::cases(), true);
    }

    /**
     * 低い順の段階名（score は 1 始まりのこの位置）。
     *
     * @return list<string>
     */
    public static function levels(): array
    {
        return array_map(fn (self $severity) => $severity->level(), self::cases());
    }
}
