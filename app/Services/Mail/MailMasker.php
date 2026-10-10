<?php

namespace App\Services\Mail;

use App\Models\MailSetting;

/**
 * codex に渡す前に、メール本文から個人を特定しうる記述を伏せる。
 *
 * マスク後のテキストだけを codex(ChatGPT) に送る。原文は source_documents に残し、
 * 画面で読むのは本人なので伏せない。
 *
 * 企業側の担当者名は残す（面接相手として有用な情報のため）。
 */
class MailMasker
{
    /** メールアドレス。ローカル部に + や . を含む形も拾う */
    private const EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    /**
     * 日本の電話番号。先頭 0 または +81 を必須にして、日付（2026-08-17）や
     * 金額（4,000,000）を巻き込まないようにする。
     * 区切りはハイフン・空白・ドット・括弧を許容する（03(1234)5678 の形が実際にある）。
     */
    private const PHONE = '/(?<![\d\-])(?:\+81[-\s.]?\d{1,4}|0\d{1,4})[-\s.()]?\d{1,4}[-\s.()]?\d{3,4}(?![\d\-])/';

    public function mask(string $text): string
    {
        // 全角英数字・記号を半角へ寄せてから照合する。
        // 「０９０－１２３４－５６７８」のような全角表記を取りこぼさないため。
        // codex へ渡すのはこの整形後のテキストで、原文は source_documents に残る
        $text = mb_convert_kana($text, 'as');

        // 設定された語（氏名など）を先に伏せる。メールアドレス形式で指定される場合もあるため順序が先
        foreach ($this->maskWords() as $word) {
            $text = str_replace($word, '[本人情報]', $text);
        }

        $text = preg_replace(self::EMAIL, '[メールアドレス]', $text);
        if ($text === null) {
            throw new \RuntimeException('メールアドレスのマスクに失敗しました: '.preg_last_error_msg());
        }

        $masked = preg_replace(self::PHONE, '[電話番号]', $text);
        if ($masked === null) {
            throw new \RuntimeException('電話番号のマスクに失敗しました: '.preg_last_error_msg());
        }

        return $masked;
    }

    /**
     * 追加でマスクする文字列（本人の氏名など）。設定画面から入力し、暗号化して保存している。
     * 長いものから順に置換して、部分一致で崩れるのを防ぐ。
     *
     * @return array<int, string>
     */
    private function maskWords(): array
    {
        $words = collect(explode(',', (string) MailSetting::query()->first()?->mask_words))
            ->map(fn (string $w) => trim($w))
            ->filter()
            ->sortByDesc(fn (string $w) => mb_strlen($w))
            ->values()
            ->all();

        return $words;
    }
}
