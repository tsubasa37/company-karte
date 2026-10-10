<?php

namespace App\Services\Google;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Gmail API から該当メールだけを取得する。
 *
 * 検索クエリ(q)をサーバー側に投げるため、条件に合わないメールは手元に落ちてこない。
 * 読み取りのみで、既読化・ラベル付け・削除・送信は一切行わない。
 */
class GmailClient
{
    private const BASE = 'https://gmail.googleapis.com/gmail/v1/users/me';

    /** 1ページあたりの要求件数。Gmail はこれより少なく返すことがあるので続きは pageToken で辿る */
    private const PAGE_SIZE = 100;

    /** 辿るページ数の上限。件数が増えない応答で回り続けないための歯止め（超えたら例外） */
    private const MAX_PAGES = 20;

    /**
     * 条件に一致するメールIDを新しい順に、$limit 件まで返す。
     *
     * Gmail は1回の応答で全件を返さず nextPageToken で続きを示す。
     * 1ページ目だけを見ると、窓の中に上限を超えて溜まったメールへ永久に辿り着けない
     * （毎回同じ先頭ページが返る）ため、$limit に届くまでページを辿る。
     *
     * @return array<int, string>
     */
    public function listMessageIds(string $accessToken, string $query, int $limit): array
    {
        $ids = [];
        $pageToken = null;
        $pages = 0;

        do {
            if (++$pages > self::MAX_PAGES) {
                throw new \RuntimeException(
                    'Gmail API: メール一覧のページ送りが'.self::MAX_PAGES.'ページを超えました（取得条件を狭めてください）'
                );
            }

            $response = Http::withToken($accessToken)->get(self::BASE.'/messages', array_filter([
                'q' => $query,
                'maxResults' => min(self::PAGE_SIZE, $limit - count($ids)),
                'pageToken' => $pageToken,
            ], fn ($v) => $v !== null));

            $this->assertOk($response, 'メール一覧の取得');

            foreach ($response->json('messages') ?? [] as $message) {
                $ids[] = $message['id'];
            }

            $pageToken = $response->json('nextPageToken');
        } while ($pageToken !== null && count($ids) < $limit);

        return array_slice($ids, 0, $limit);
    }

    /**
     * メール1通を取り込み用の形に整えて返す。
     *
     * @return array{id: string, from: string, from_domain: string, subject: string, received_at: Carbon, body: string}
     */
    public function fetchMessage(string $accessToken, string $id): array
    {
        $response = Http::withToken($accessToken)->get(self::BASE.'/messages/'.$id, ['format' => 'full']);

        $this->assertOk($response, 'メール本文の取得');

        $payload = $response->json('payload') ?? [];
        $headers = $this->headers($payload);
        $from = $headers['from'] ?? '';

        return [
            'id' => $id,
            'from' => $from,
            'from_domain' => $this->domainOf($from),
            'subject' => $headers['subject'] ?? '(件名なし)',
            'received_at' => $this->receivedAt($response->json('internalDate')),
            'body' => $this->body($payload),
        ];
    }

    private function assertOk(Response $response, string $what): void
    {
        if ($response->failed()) {
            throw new \RuntimeException(
                "Gmail API: {$what}に失敗しました（HTTP ".$response->status().'）: '.$response->body()
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function headers(array $payload): array
    {
        return collect($payload['headers'] ?? [])
            ->mapWithKeys(fn (array $h) => [mb_strtolower((string) $h['name']) => (string) $h['value']])
            ->all();
    }

    /**
     * From ヘッダからドメインを取り出す。
     *
     * 表示名にも @ や山括弧を書けるため（例: "HR@team" <scout@example.com>）、
     * 山括弧があればその中だけを実アドレスとして見る。文字列の最初の @ を拾うと、
     * 表示名を細工したメールで許可ドメインの判定が狂う。
     */
    private function domainOf(string $from): string
    {
        $address = preg_match('/<([^<>]*)>\s*$/', $from, $bracketed) === 1
            ? $bracketed[1]
            : $from;

        if (preg_match('/@([A-Za-z0-9.\-]+)$/', trim($address), $matches) !== 1) {
            throw new \RuntimeException("From ヘッダからドメインを取り出せませんでした: {$from}");
        }

        return mb_strtolower($matches[1]);
    }

    /** internalDate はミリ秒のエポック */
    private function receivedAt(?string $internalDate): Carbon
    {
        if (blank($internalDate)) {
            throw new \RuntimeException('Gmail API: internalDate が空でした（受信日時を決められません）');
        }

        return Carbon::createFromTimestampMs((int) $internalDate);
    }

    /**
     * 本文を取り出す。text/plain を優先し、無ければ text/html をテキスト化する。
     * どちらも取れない場合は黙って空文字にせず例外にする。
     *
     * @param  array<string, mixed>  $payload
     */
    private function body(array $payload): string
    {
        $plain = $this->findPart($payload, 'text/plain');
        if ($plain !== null) {
            return $this->decode($plain);
        }

        $html = $this->findPart($payload, 'text/html');
        if ($html !== null) {
            return $this->htmlToText($this->decode($html));
        }

        throw new \RuntimeException('Gmail API: 本文（text/plain も text/html）が見つかりませんでした');
    }

    /**
     * MIME ツリーを辿って該当パートの本体データを探す。
     *
     * @param  array<string, mixed>  $part
     */
    private function findPart(array $part, string $mimeType): ?string
    {
        if (($part['mimeType'] ?? '') === $mimeType && filled($part['body']['data'] ?? null)) {
            return $part['body']['data'];
        }

        foreach ($part['parts'] ?? [] as $child) {
            $found = $this->findPart($child, $mimeType);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** Gmail は base64url（-, _ を使う・パディングなし）で返す */
    private function decode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        if ($decoded === false) {
            throw new \RuntimeException('Gmail API: 本文のデコードに失敗しました');
        }

        return $decoded;
    }

    private function htmlToText(string $html): string
    {
        // strip_tags はタグを外すだけで中身を残すため、先に style/script の中身ごと落とす
        // （CSS が本文の先頭に混ざると codex の判定材料が汚れる）
        $text = preg_replace('#<(style|script)[^>]*>.*?</\1>#is', '', $html);
        $text = $text === null ? null : preg_replace('#<(br|/p|/div|/tr)[^>]*>#i', "\n", $text);

        if ($text === null) {
            throw new \RuntimeException('Gmail API: HTML 本文の整形に失敗しました: '.preg_last_error_msg());
        }

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
