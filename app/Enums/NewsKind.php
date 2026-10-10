<?php

namespace App\Enums;

/**
 * ニュース記事の種類（機械判定の choice の候補）。値は news_screenings.kind に保存するため変えない。
 */
enum NewsKind: string
{
    case Incident = 'incident';
    case Performance = 'performance';
    case Organization = 'organization';
    case Product = 'product';
    case Hiring = 'hiring';
    case Other = 'other';

    /** 画面の表示名。判定の候補の説明にも同じ文を使う */
    public function label(): string
    {
        return match ($this) {
            self::Incident => '不祥事・訴訟・行政処分',
            self::Performance => '業績・決算・資金調達',
            self::Organization => '人事・組織・経営体制',
            self::Product => '製品・事業・提携',
            self::Hiring => '採用・働き方',
            self::Other => 'その他',
        };
    }

    /**
     * 判定の choice の criteria（候補名 => 説明）。
     *
     * @return array<string, string>
     */
    public static function criteria(): array
    {
        $criteria = [];
        foreach (self::cases() as $kind) {
            $criteria[$kind->value] = $kind->label();
        }

        return $criteria;
    }
}
