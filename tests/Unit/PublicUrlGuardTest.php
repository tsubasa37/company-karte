<?php

namespace Tests\Unit;

use App\Services\DnsResolver;
use App\Services\PublicUrlGuard;
use PHPUnit\Framework\TestCase;

class PublicUrlGuardTest extends TestCase
{
    public function test_http_https以外のスキームは拒否される(): void
    {
        foreach (['javascript:alert(1)', 'file:///etc/passwd', 'ftp://example.com/x', 'gopher://example.com/'] as $url) {
            $this->assertRejected($url, 'http / https 以外');
        }
    }

    public function test_ホスト部のないurlは拒否される(): void
    {
        // parse_url が解釈できない形（http:///path）と、スキームだけの形の両方を弾く
        $this->assertRejected('http:///path/only', 'URLの形式が不正です');
        $this->assertRejected('http:', 'ホスト名');
    }

    public function test_内部ipリテラルは拒否される(): void
    {
        foreach (['http://127.0.0.1/admin', 'http://169.254.169.254/latest/meta-data/', 'http://10.0.0.1/', 'https://192.168.1.1/', 'http://172.16.0.1/'] as $url) {
            $this->assertRejected($url, '内部ネットワーク');
        }
    }

    public function test_ipv6のループバックはブラケット付きでも拒否される(): void
    {
        $this->assertRejected('http://[::1]:8080/', '内部ネットワーク');
    }

    public function test_共有アドレス帯や予約帯も拒否される(): void
    {
        // filter_var の NO_PRIV_RANGE / NO_RES_RANGE では公開IP扱いになる帯
        foreach ([
            'http://100.64.0.1/',          // CGNAT（Tailscale の Tailnet）
            'http://100.100.100.100/',     // Tailscale MagicDNS
            'http://192.0.0.1/',           // IETF プロトコル割り当て
            'http://198.18.0.1/',          // ベンチマーク用
            'http://240.0.0.1/',           // 予約
            'http://0.0.0.0/',             // このネットワーク
        ] as $url) {
            $this->assertRejected($url, '内部ネットワーク');
        }
    }

    public function test_ipv4を埋め込めるipv6帯は拒否される(): void
    {
        foreach ([
            'http://[64:ff9b::7f00:1]/',    // NAT64 経由の 127.0.0.1
            'http://[2002:7f00:1::]/',      // 6to4 経由の 127.0.0.1
            'http://[::ffff:127.0.0.1]/',   // IPv4射影
            'http://[2001:db8::1]/',        // ドキュメント用
            'http://[fe80::1]/',            // リンクローカル
            'http://[fc00::1]/',            // ユニークローカル
        ] as $url) {
            $this->assertRejected($url, '内部ネットワーク');
        }
    }

    public function test_拒否帯の境界の外は公開扱いになる(): void
    {
        // 100.64.0.0/10 の直前と直後。マスク計算が1ビットずれていないことの確認
        foreach (['https://100.63.255.255/', 'https://100.128.0.1/'] as $url) {
            $this->guard()->assertFetchable($url);
        }

        $this->addToAssertionCount(1);
    }

    public function test_公開ipリテラルは通過する(): void
    {
        $this->assertSame(
            ['93.184.216.34'],
            $this->guard()->assertFetchable('https://93.184.216.34/article'),
        );
    }

    public function test_公開ipに解決されるホスト名は通過し検証済みipを返す(): void
    {
        // 接続先を固定するのは呼び出し側なので、検証したIPをそのまま返す必要がある
        $this->assertSame(
            ['93.184.216.34', '93.184.216.35'],
            $this->guard(['news.example.com' => ['93.184.216.34', '93.184.216.35']])
                ->assertFetchable('https://news.example.com/articles/1'),
        );
    }

    public function test_内部ipに解決されるホスト名は拒否される(): void
    {
        $guard = $this->guard(['internal.example.com' => ['10.0.0.5']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/内部ネットワーク/');

        $guard->assertFetchable('https://internal.example.com/');
    }

    public function test_解決結果に内部ipが一つでも含まれると拒否される(): void
    {
        $guard = $this->guard(['mixed.example.com' => ['93.184.216.34', '127.0.0.1']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/内部ネットワーク/');

        $guard->assertFetchable('https://mixed.example.com/');
    }

    public function test_名前解決に失敗したら拒否される(): void
    {
        $this->assertRejected('https://unknown.example.com/', '解決できない');
    }

    public function test_10進表記のホストは解決できず拒否される(): void
    {
        // http://2130706433/ は 127.0.0.1 の別表記。IPリテラルとしては読めないため
        // ホスト名扱いになり、名前解決できず fail-closed で落ちる。
        $this->assertRejected('http://2130706433/', '解決できない');
    }

    private function assertRejected(string $url, string $messageFragment): void
    {
        try {
            $this->guard()->assertFetchable($url);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($messageFragment, $e->getMessage());

            return;
        }

        $this->fail("拒否されるべきURLが通過した: {$url}");
    }

    /**
     * 実DNSを引かない偽リゾルバを差した Guard（テストをオフラインで動かすため）。
     *
     * @param  array<string, array<int, string>>  $map  ホスト名 => 解決結果のIP
     */
    private function guard(array $map = []): PublicUrlGuard
    {
        $resolver = new class($map) extends DnsResolver
        {
            /**
             * @param  array<string, array<int, string>>  $map
             */
            public function __construct(private readonly array $map) {}

            /**
             * @return array<int, string>
             */
            public function resolve(string $host): array
            {
                return $this->map[$host] ?? [];
            }
        };

        return new PublicUrlGuard($resolver);
    }
}
