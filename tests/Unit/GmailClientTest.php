<?php

namespace Tests\Unit;

use App\Services\Google\GmailClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Gmail API のレスポンスを取り込み用の形に直す部分。
 * 本文が取れない・From が壊れている場合に黙って空文字を返さないことも確かめる。
 */
class GmailClientTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function fakeMessage(array $payload, string $internalDate = '1787200000000'): void
    {
        Http::fake([
            'gmail.googleapis.com/*' => Http::response([
                'id' => 'msg-1',
                'internalDate' => $internalDate,
                'payload' => $payload,
            ]),
        ]);
    }

    private function encode(string $text): string
    {
        return rtrim(strtr(base64_encode($text), '+/', '-_'), '=');
    }

    public function test_プレーンテキスト本文を取り出す(): void
    {
        $this->fakeMessage([
            'mimeType' => 'multipart/alternative',
            'headers' => [
                ['name' => 'From', 'value' => '採用担当 <scout@bizreach.jp>'],
                ['name' => 'Subject', 'value' => 'スカウトのご案内'],
            ],
            'parts' => [
                ['mimeType' => 'text/html', 'body' => ['data' => $this->encode('<p>HTML版</p>')]],
                ['mimeType' => 'text/plain', 'body' => ['data' => $this->encode('プレーン版の本文')]],
            ],
        ]);

        $message = app(GmailClient::class)->fetchMessage('token', 'msg-1');

        $this->assertSame('プレーン版の本文', $message['body']);
        $this->assertSame('bizreach.jp', $message['from_domain']);
        $this->assertSame('スカウトのご案内', $message['subject']);
    }

    public function test_プレーンが無ければhtmlをテキスト化する(): void
    {
        $this->fakeMessage([
            'mimeType' => 'text/html',
            'headers' => [['name' => 'From', 'value' => 'scout@bizreach.jp']],
            'body' => ['data' => $this->encode('<div>1行目<br>2行目</div>')],
        ]);

        $message = app(GmailClient::class)->fetchMessage('token', 'msg-1');

        $this->assertStringContainsString('1行目', $message['body']);
        $this->assertStringContainsString('2行目', $message['body']);
        $this->assertStringNotContainsString('<br>', $message['body']);
    }

    public function test_入れ子のパートからも本文を見つける(): void
    {
        $this->fakeMessage([
            'mimeType' => 'multipart/mixed',
            'headers' => [['name' => 'From', 'value' => 'scout@bizreach.jp']],
            'parts' => [[
                'mimeType' => 'multipart/alternative',
                'parts' => [['mimeType' => 'text/plain', 'body' => ['data' => $this->encode('入れ子の本文')]]],
            ]],
        ]);

        $this->assertSame('入れ子の本文', app(GmailClient::class)->fetchMessage('token', 'msg-1')['body']);
    }

    public function test_本文が無ければ黙って空文字にせず例外にする(): void
    {
        $this->fakeMessage([
            'mimeType' => 'multipart/mixed',
            'headers' => [['name' => 'From', 'value' => 'scout@bizreach.jp']],
            'parts' => [['mimeType' => 'application/pdf', 'body' => ['attachmentId' => 'x']]],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('本文');

        app(GmailClient::class)->fetchMessage('token', 'msg-1');
    }

    public function test_fromからドメインを取れなければ例外にする(): void
    {
        $this->fakeMessage([
            'mimeType' => 'text/plain',
            'headers' => [['name' => 'From', 'value' => '差出人不明']],
            'body' => ['data' => $this->encode('本文')],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ドメイン');

        app(GmailClient::class)->fetchMessage('token', 'msg-1');
    }

    public function test_internal_dateが空なら例外にする(): void
    {
        $this->fakeMessage([
            'mimeType' => 'text/plain',
            'headers' => [['name' => 'From', 'value' => 'scout@bizreach.jp']],
            'body' => ['data' => $this->encode('本文')],
        ], internalDate: '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('internalDate');

        app(GmailClient::class)->fetchMessage('token', 'msg-1');
    }

    public function test_表示名に含まれる_atマークに惑わされない(): void
    {
        // 表示名にも @ を書けるため、最初の @ を拾うと許可ドメイン判定が狂う
        $this->fakeMessage([
            'mimeType' => 'text/plain',
            'headers' => [['name' => 'From', 'value' => '"HR@recruit-team" <scout@bizreach.jp>']],
            'body' => ['data' => $this->encode('本文')],
        ]);

        $this->assertSame('bizreach.jp', app(GmailClient::class)->fetchMessage('token', 'msg-1')['from_domain']);
    }

    public function test_表示名に偽装アドレスを書かれても実アドレスを見る(): void
    {
        $this->fakeMessage([
            'mimeType' => 'text/plain',
            'headers' => [['name' => 'From', 'value' => '"fake <fake@bizreach.jp>" <attacker@evil.example>']],
            'body' => ['data' => $this->encode('本文')],
        ]);

        $this->assertSame('evil.example', app(GmailClient::class)->fetchMessage('token', 'msg-1')['from_domain']);
    }

    public function test_styleタグの中身が本文に混ざらない(): void
    {
        $this->fakeMessage([
            'mimeType' => 'text/html',
            'headers' => [['name' => 'From', 'value' => 'scout@bizreach.jp']],
            'body' => ['data' => $this->encode('<style>.a{color:red}</style><p>面接のご案内</p>')],
        ]);

        $body = app(GmailClient::class)->fetchMessage('token', 'msg-1')['body'];

        $this->assertStringNotContainsString('color:red', $body);
        $this->assertStringContainsString('面接のご案内', $body);
    }
}
