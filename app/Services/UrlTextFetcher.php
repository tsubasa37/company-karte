<?php

namespace App\Services;

/**
 * URL のページ本文をテキスト抽出する簡易版（defuddle 相当の最小実装）。
 * 自分が閲覧できる公開ページの取り込み用。失敗は例外（サイレント失敗禁止）。
 *
 * 取得そのもの（SSRF ガード・検証済みIPへの接続固定・リダイレクト追従・サイズと
 * 途中切断の検査）は GuardedHttpFetcher に集約している。ここは HTML → テキストと、
 * 「分析材料になる本文が取れたか」の品質判定だけを担う。
 */
class UrlTextFetcher
{
    /** リダイレクトを含めた総取得時間の上限（秒）。1ホップごとの上限では合計が伸びてしまうため */
    private const TOTAL_TIMEOUT_SECONDS = 20;

    /**
     * 実効本文（SourceTextCleaner でナビ・フッター等の定型行を落とした後）の下限文字数。
     *
     * 400字: ニュースの短報でも本文は400字程度ある。これを下回るのは
     * 「JavaScriptを有効にしてください」等の入れ物だけのページで、分析材料にならない。
     * 弾かずに保存すると、カルテ上は「ソース1件あり」なのに中身が無い状態になる。
     */
    private const MIN_EFFECTIVE_CHARS = 400;

    /**
     * ログイン壁（会員登録すれば続きが読める）判定の対象にする実効文字数の上限。
     *
     * 4,000字: これ以上あるページは本文が十分に取れているので、記事内に会員登録の
     * 誘導が混ざっていても本文として扱う。実データの OpenWork 企業ページ（未ログイン）は
     * 全体で 2,621字・中身はナビ文言と口コミ1件のティーザーだけで、この窓に収まる。
     */
    private const LOGIN_WALL_MAX_CHARS = 4000;

    /**
     * ログイン壁と判定する誘導語の出現回数。
     *
     * 3回: 本文中で会員登録・ログインを1〜2回案内する普通のページ（記事末尾のバナー等）は
     * 通し、ぼかした本文ごとに登録を促すティーザーページ（口コミサイト・有料記事）を弾く。
     */
    private const LOGIN_WALL_MIN_HITS = 3;

    /**
     * 会員登録・ログインの誘導語。長い語（無料会員登録）は短い語（会員登録）に含まれて
     * 二重に数えられるため、短い側だけを挙げる。
     */
    private const LOGIN_WALL_WORDS = [
        '会員登録', '会員限定', '会員になると', '無料登録',
        'ログインして', 'ログインが必要', 'ログインまたは',
        '続きを読むには', '続きを見るには', 'すべて見るには',
    ];

    public function __construct(
        private readonly GuardedHttpFetcher $fetcher,
        private readonly SourceTextCleaner $cleaner,
    ) {}

    public function fetch(string $url): string
    {
        $html = $this->fetcher->fetchBody($url, self::TOTAL_TIMEOUT_SECONDS);

        // script/style を除去 → タグ除去 → 空白正規化
        $text = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n\s*\n\s*\n+/u', "\n\n", $text) ?? $text;
        $text = trim($text);

        if ($text === '') {
            throw new \RuntimeException("URLから本文を抽出できませんでした: {$url}");
        }

        $this->assertUsableBody($text, $url);

        // 保存用のクリーニングは投入経路（UpsertSourceDocumentAction）が行うため、
        // ここでは抽出したままのテキストを返す（判定にだけクリーニング後の長さを使う）。
        return $text;
    }

    /**
     * 「取得はできたが分析材料にならない本文」を保存前に弾く。
     * ログイン壁のティーザーを取得成功として保存すると、カルテ上は材料があるように見えて
     * 分析結果が空になるため例外にする（呼び出し側がフォームエラーへ変換する）。
     */
    private function assertUsableBody(string $text, string $url): void
    {
        $effective = mb_strlen($this->cleaner->clean($text)['text']);

        if ($effective < self::MIN_EFFECTIVE_CHARS) {
            throw new \RuntimeException(
                "本文がほとんど取れませんでした（定型行を除くと{$effective}字。"
                .self::MIN_EFFECTIVE_CHARS.'字未満は分析材料になりません）: '.$url
            );
        }

        // 誘導語は「ページが実際に表示している文言」で数える（定型行の除去後だと
        // ナビの「ログイン」等が消えて壁の判定材料が減るため、除去前のテキストを見る）。
        $hits = $this->loginWallHits($text);

        if ($effective < self::LOGIN_WALL_MAX_CHARS && $hits >= self::LOGIN_WALL_MIN_HITS) {
            throw new \RuntimeException(
                "ログイン・会員登録が必要なページのようです（本文{$effective}字に会員登録の誘導が{$hits}箇所）: ".$url
            );
        }
    }

    private function loginWallHits(string $text): int
    {
        $hits = 0;
        foreach (self::LOGIN_WALL_WORDS as $word) {
            $hits += mb_substr_count($text, $word);
        }

        return $hits;
    }
}
