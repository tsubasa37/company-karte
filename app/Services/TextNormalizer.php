<?php

namespace App\Services;

/**
 * 表記ゆれを潰した「照合用テキスト」を作る共通処理。
 *
 * 引用の実在照合（QuoteVerifier）と企業名の名寄せ（CompanyNameNormalizer）で
 * 同じ規則を使う。片側だけ規則が違うと、同じ文字列が一致しなくなる。
 * 表示用ではなく突合専用（元の見た目は復元できない）。
 */
class TextNormalizer
{
    /**
     * 照合用の正規化。
     * 全角英数→半角 / 半角カナ→全角カナ（濁点は1文字に合成） / 空白・改行の除去 /
     * 引用符の除去 / 波ダッシュ・ハイフンの統一 / 中黒（・）の除去 / 小文字化。
     *
     * 厚労省の公表事案PDFは全角英数・半角カナが混在し、社名の中黒も揺れる
     * （「ジェイ・ティ」「ジェイティ」）ため、そこまで含めて潰す。
     */
    public static function normalize(string $text): string
    {
        $normalized = mb_convert_kana($text, 'asKV');
        $normalized = (string) preg_replace('/\s|　/u', '', $normalized);
        $normalized = (string) preg_replace('/["\'“”「」『』‘’]/u', '', $normalized);
        $normalized = (string) preg_replace('/[〜～]/u', '~', $normalized);
        $normalized = (string) preg_replace('/[－―‐–—]/u', '-', $normalized);
        // 半角中黒（･）は上の mb_convert_kana で全角（・）に寄っている
        $normalized = (string) preg_replace('/・/u', '', $normalized);

        return mb_strtolower($normalized);
    }
}
