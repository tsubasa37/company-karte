<?php

namespace App\Services;

/**
 * 取り込んだページ本文から、分析の材料にならない定型行（ナビ・フッター・応募ボタン・
 * 関連求人のリンク列など）を落とす。上限文字数を給与や勤務地の記載に使うため。
 *
 * 判定は行単位のパターンのみ。文章の意味では判断しない（AIを使わない確定処理）。
 */
class SourceTextCleaner
{
    /** この文字数以下の行は、下のキーワードに一致した場合だけ落とす（本文の短い見出しを守る） */
    private const SHORT_LINE = 30;

    /** 単独行として現れたら落とす定型文（完全一致・前後空白は無視） */
    private const EXACT_NOISE = [
        'この求人に応募する', '応募する', '気になる', '気になるリストに追加', 'お気に入りに追加',
        // 実データで残った求人サイト共通の導線語（マイナビ等のヘッダー行）
        '求人を探す', '転職フェア', '簡単1分', '転職TOP',
        'ログイン', '新規会員登録', '会員登録', 'ログアウト', 'マイページ',
        '検索', '検索条件を変更する', '絞り込む', 'すべて見る', 'もっと見る', '続きを読む',
        '戻る', '次へ', '前へ', 'トップへ戻る', 'ページの先頭へ', 'ページトップへ',
        'シェア', 'ツイート', 'はてなブックマーク', 'LINEで送る',
        'メニュー', 'サイトマップ', 'よくあるご質問', 'お問い合わせ',
        'この求人を保存', '印刷する', 'PDFで見る',
    ];

    /** 含まれていたら落とす定型文（部分一致・短い行のみ対象） */
    private const CONTAINS_NOISE = [
        'Cookie', 'クッキー', 'プライバシーポリシー', '利用規約', '個人情報の取扱',
        'All Rights Reserved', 'Copyright', '©',
        'JavaScriptを有効', 'ブラウザの設定',
        // カタカナ表記のサイトがある（実データ: 「オススメの求人」が残った）
        'おすすめの求人', 'オススメの求人', '関連する求人', 'この求人を見た人はこんな求人も見ています',
        '最近見た求人', '閲覧履歴',
    ];

    /**
     * @return array{text: string, removedLines: int}
     */
    public function clean(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        $kept = [];
        $removed = 0;
        $previous = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($this->isNoise($trimmed) || $this->isDuplicate($trimmed, $previous)) {
                $removed++;

                continue;
            }

            $kept[] = $trimmed;
            if ($trimmed !== '') {
                $previous = $trimmed;
            }
        }

        return [
            'text' => $this->collapseBlankLines(implode("\n", $kept)),
            'removedLines' => $removed,
        ];
    }

    private function isNoise(string $line): bool
    {
        if ($line === '') {
            return false; // 空行は後段でまとめて圧縮する
        }

        if (in_array($line, self::EXACT_NOISE, true)) {
            return true;
        }

        // 長い行は本文である可能性が高いので、部分一致では落とさない
        if (mb_strlen($line) > self::SHORT_LINE) {
            return false;
        }

        foreach (self::CONTAINS_NOISE as $needle) {
            if (mb_stripos($line, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /** 直前と同じ行（メニューの繰り返し等）は落とす */
    private function isDuplicate(string $line, ?string $previous): bool
    {
        return $line !== '' && $line === $previous;
    }

    private function collapseBlankLines(string $text): string
    {
        return trim((string) preg_replace('/\n{3,}/u', "\n\n", $text));
    }
}
