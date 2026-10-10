<?php

namespace App\Services;

use App\Models\Company;

/**
 * 法人格語・表記ゆれを除去した正規化キーで企業名を照合する。
 * クリップ取り込み・一覧一括登録・労基リスト突合で共通利用。
 * 自分用途で企業数が限られるため全件比較で足りる。
 */
class CompanyNameNormalizer
{
    public function normalize(string $name): string
    {
        // 先に照合用の正規化（全角英数→半角・半角カナ→全角カナ・空白/中黒の除去）を通し、
        // その後で法人格語を落とす。全角の（株）も半角の (株) に寄るため、法人格の
        // パターンは半角だけを見ればよい。
        $normalized = TextNormalizer::normalize($name);

        return (string) preg_replace(
            [
                '/(株式会社|有限会社|合同会社|合資会社|合名会社|一般社団法人|一般財団法人)/u',
                '/\((株|有|同)\)/u',
            ],
            '',
            $normalized
        );
    }

    /** 正規化キーが一致する既存企業を返す */
    public function findExisting(string $name): ?Company
    {
        return Company::all()->first(
            fn (Company $c): bool => $this->normalize($c->name) === $this->normalize($name)
        );
    }
}
