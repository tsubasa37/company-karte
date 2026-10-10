<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * 企業名でニュース記事の一覧（タイトル・実URL・媒体・日付）を取得する。
 * RSS は Bing News を使う。Google News はリンクが実URLでない（リダイレクトIDの
 * 難読化形式）ため記事本文の取り込みに使えず、採用しない。
 * 手動取得ボタン用だが、将来のスケジュール実行でもこのまま再利用する。失敗は例外。
 */
class NewsFeedService
{
    private const MAX_ARTICLES = 30;

    /** 機械判定の材料にする概要の上限（字） */
    private const MAX_DESCRIPTION_CHARS = 300;

    /**
     * @return array<int, array{title: string, link: string, source: string, publishedAt: string|null, description: string}>
     */
    public function search(string $companyName): array
    {
        $url = 'https://www.bing.com/news/search?'.http_build_query([
            'q' => '"'.$companyName.'"',
            'format' => 'RSS',
            'setmkt' => 'ja-JP',
            'setlang' => 'ja',
        ]);

        $response = Http::timeout(20)
            ->withHeaders(['User-Agent' => 'company-karte/1.0 (personal research)'])
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException("ニュースRSSの取得に失敗しました（HTTP {$response->status()}）");
        }

        return $this->parse($response->body());
    }

    /**
     * @return array<int, array{title: string, link: string, source: string, publishedAt: string|null, description: string}>
     */
    private function parse(string $rss): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($rss);

        if ($xml === false || ! isset($xml->channel)) {
            throw new \RuntimeException('ニュースRSSの解析に失敗しました（形式が想定と異なります）');
        }

        $articles = [];

        foreach ($xml->channel->item as $item) {
            $link = trim((string) $item->link);

            // http/https 以外（javascript: 等）は画面のリンク・サーバー側取得の両方に渡さない
            if (! $this->isWebLink($link)) {
                continue;
            }

            $articles[] = [
                'title' => trim((string) $item->title),
                'link' => $link,
                // Bing News の item には媒体名要素が無いため、記事URLのホスト名を媒体表示に使う
                'source' => (string) (parse_url($link, PHP_URL_HOST) ?: ''),
                'publishedAt' => $this->formatDate((string) $item->pubDate),
                'description' => $this->plainText((string) $item->description),
            ];

            if (count($articles) >= self::MAX_ARTICLES) {
                break;
            }
        }

        return $articles;
    }

    private function isWebLink(string $url): bool
    {
        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    /** 概要のタグを剥がし、空白を詰めて上限まで切る（無い item は空文字） */
    private function plainText(string $html): string
    {
        $text = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            ?? throw new \RuntimeException('ニュースRSSの概要を解析できませんでした（文字コードが不正です）');

        return mb_substr(trim($text), 0, self::MAX_DESCRIPTION_CHARS);
    }

    private function formatDate(string $pubDate): ?string
    {
        if (trim($pubDate) === '') {
            return null;
        }

        return Carbon::parse($pubDate)->timezone('Asia/Tokyo')->format('Y-m-d');
    }
}
