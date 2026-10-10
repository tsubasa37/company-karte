<?php

namespace App\Services\Ai;

use App\Services\TextNormalizer;

/**
 * AI が返した引用（quote）が、実際の求人票本文に存在するかを PHP で照合する。
 * 対象は P1 の主張（filter）と P3 の危険フレーズ検出（filterRhetoric）。
 * 存在しない引用は AI の作文（ハルシネーション）として落とす。
 * 判定は機械的に行い、codex には委ねない。
 */
class QuoteVerifier
{
    /** これより短い引用は偶然一致しやすいため、実在照合の対象にしない（落とさず残す） */
    private const MIN_QUOTE_LENGTH = 6;

    /**
     * 引用が原文に存在する主張だけを返し、落とした件数も返す。
     *
     * @param  array<string, mixed>  $p1Output  P1の出力（claims を持つ）
     * @return array<string, mixed> claims を絞り込んだ出力（dropped_claims / dropped_claims_count 付き）
     */
    public function filter(array $p1Output, string $sourceText): array
    {
        $claims = $p1Output['claims'] ?? [];
        if (! is_array($claims) || $claims === []) {
            return $p1Output + ['dropped_claims' => [], 'dropped_claims_count' => 0];
        }

        $haystack = $this->normalize($sourceText);

        $kept = [];
        $dropped = [];

        foreach ($claims as $claim) {
            if ($this->exists((string) ($claim['quote'] ?? ''), $haystack)) {
                $kept[] = $claim;

                continue;
            }

            $dropped[] = [
                'claim' => (string) ($claim['claim'] ?? ''),
                'quote' => (string) ($claim['quote'] ?? ''),
            ];
        }

        $p1Output['claims'] = $kept;
        $p1Output['dropped_claims'] = $dropped;
        $p1Output['dropped_claims_count'] = count($dropped);

        return $p1Output;
    }

    /**
     * P3 の危険フレーズ検出（rhetoric_flags）のうち、原文引用（quote）が求人票本文に
     * 実在するものだけを返す。P1 と同じく、作文された引用は残さない。
     *
     * 照合対象は quote（原文引用）。phrase は辞書ラベル寄りの「検出した表現」で、
     * 原文と一字一句同じとは限らないため、phrase で照合すると正当な検出まで落ちる。
     *
     * @param  array<string, mixed>  $p3Output  P3の出力（rhetoric_flags を持つ）
     * @return array<string, mixed> rhetoric_flags を絞り込んだ出力（dropped_flags_count 付き）
     */
    public function filterRhetoric(array $p3Output, string $sourceText): array
    {
        $flags = $p3Output['rhetoric_flags'] ?? [];
        if (! is_array($flags) || $flags === []) {
            return $p3Output + ['dropped_flags_count' => 0];
        }

        $haystack = $this->normalize($sourceText);

        $kept = array_values(array_filter(
            $flags,
            fn ($flag): bool => $this->exists((string) ($flag['quote'] ?? ''), $haystack),
        ));

        $p3Output['rhetoric_flags'] = $kept;
        $p3Output['dropped_flags_count'] = count($flags) - count($kept);

        return $p3Output;
    }

    /**
     * 引用が原文にあるか。改行・空白・記号の差では落とさない（AIが整形して返すため）。
     */
    private function exists(string $quote, string $normalizedHaystack): bool
    {
        $needle = $this->normalize($quote);

        // 短すぎる引用は照合の信頼性が低いので、判定を保留して残す
        if (mb_strlen($needle) < self::MIN_QUOTE_LENGTH) {
            return true;
        }

        return mb_strpos($normalizedHaystack, $needle) !== false;
    }

    /**
     * 照合用の正規化は TextNormalizer に集約する（企業名の名寄せと同じ規則を使う）。
     */
    private function normalize(string $text): string
    {
        return TextNormalizer::normalize($text);
    }
}
