<?php

namespace App\Services;

/**
 * 外部取得してよい URL かを検証する（SSRF 対策）。
 * ニュースRSS由来など第三者が半分制御する URL も同じ取り込み口へ流れ込むため、
 * 本人がボタンを押しただけで内部ネットワークを読み出せてしまう経路をここで一律に塞ぐ。
 * 取得本文は画面に全文表示され codex のプロンプトにも入るので、判定は fail-closed。
 * 問題があれば例外（サイレントに握り潰さない）。
 *
 * 判定は filter_var の NO_PRIV_RANGE / NO_RES_RANGE に頼らず拒否CIDRを明示的に持つ。
 * それらのフラグは CGNAT(100.64.0.0/10 = Tailscale の Tailnet 帯)・NAT64(64:ff9b::/96)・
 * 6to4(2002::/16)・240.0.0.0/4 を「公開IP」と通してしまい、
 * 100.100.100.100 や 64:ff9b::7f00:1 のように手元のホストへ届く宛先が素通りするため。
 *
 * 検証した IP を返すのは、呼び出し側（UrlTextFetcher）が接続先をその IP に固定するため。
 * ホスト名のまま接続すると実接続で名前解決がもう一度走り、
 * 2回目だけ内部IPを返す DNS リバインディングでこの検証が丸ごと無意味になる。
 */
class PublicUrlGuard
{
    /**
     * 到達させたくない IPv4 帯。プライベート・ループバックに加えて、
     * 共有/予約/テスト用の帯も塞ぐ（NAT やトンネルの向こう側が内部網であることが多いため）。
     */
    private const DENIED_IPV4 = [
        '0.0.0.0/8',          // このネットワーク（0.0.0.0 は localhost に落ちる実装がある）
        '10.0.0.0/8',         // プライベート
        '100.64.0.0/10',      // CGNAT / Tailscale の Tailnet（100.100.100.100 は MagicDNS）
        '127.0.0.0/8',        // ループバック
        '169.254.0.0/16',     // リンクローカル（169.254.169.254 はクラウドのメタデータ）
        '172.16.0.0/12',      // プライベート
        '192.0.0.0/24',       // IETF プロトコル割り当て
        '192.88.99.0/24',     // 6to4 リレーエニーキャスト
        '192.168.0.0/16',     // プライベート
        '198.18.0.0/15',      // ベンチマーク用
        '224.0.0.0/4',        // マルチキャスト
        '240.0.0.0/4',        // 予約（255.255.255.255 を含む）
    ];

    /**
     * 到達させたくない IPv6 帯。IPv4 を埋め込める帯（NAT64・6to4・Teredo・IPv4射影）は
     * 埋め込み先が内部IPでも見分けが付かないため、帯ごと拒否する。
     */
    private const DENIED_IPV6 = [
        '::/96',              // 未指定・ループバック(::1)・IPv4互換アドレス
        '::ffff:0:0/96',      // IPv4射影（::ffff:127.0.0.1）
        '64:ff9b::/96',       // NAT64（64:ff9b::7f00:1 は 127.0.0.1）
        '64:ff9b:1::/48',     // ローカル用 NAT64
        '100::/64',           // discard-only
        '2001::/32',          // Teredo（IPv4 を埋め込む）
        '2001:10::/28',       // ORCHID（廃止）
        '2001:20::/28',       // ORCHIDv2
        '2001:db8::/32',      // ドキュメント用
        '2002::/16',          // 6to4（2002:7f00:1:: は 127.0.0.1）
        'fc00::/7',           // ユニークローカル
        'fe80::/10',          // リンクローカル
        'ff00::/8',           // マルチキャスト
    ];

    public function __construct(private readonly DnsResolver $resolver) {}

    /**
     * 取得を許可できない URL なら例外を投げる。
     * 通った場合は「公開だと確認済み」の接続先IPを返す（呼び出し側はここへ接続を固定する）。
     *
     * @return array<int, string> 検証済みIP。ホストがIPリテラルならその値、ホスト名なら解決した全IP
     *
     * @throws \RuntimeException
     */
    public function assertFetchable(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false) {
            throw new \RuntimeException("URLの形式が不正です: {$url}");
        }

        $scheme = mb_strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException("http / https 以外のURLは取得できません: {$url}");
        }

        $host = $parts['host'] ?? '';

        if ($host === '') {
            throw new \RuntimeException("URLにホスト名がありません: {$url}");
        }

        $ips = $this->addressesOf($host, $url);

        foreach ($ips as $ip) {
            if ($this->isDenied($ip, $url)) {
                throw new \RuntimeException("内部ネットワーク宛のURLは取得できません（{$ip}）: {$url}");
            }
        }

        return $ips;
    }

    /**
     * 検証対象の IP 一覧。ホストが IP リテラルならその値、ホスト名なら解決した全IPを返す。
     *
     * @return array<int, string>
     */
    private function addressesOf(string $host, string $url): array
    {
        // IPv6 のリテラルは [::1] の形で入るのでブラケットを外してから判定する
        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return [$literal];
        }

        $ips = $this->resolver->resolve($host);

        if ($ips === []) {
            // 解決できないホストは中身が分からないので通さない（10進表記 http://2130706433/ 等もここで落ちる）
            throw new \RuntimeException("ホスト名を解決できないURLは取得できません: {$url}");
        }

        return $ips;
    }

    /** 拒否CIDRのどれかに入るか。解釈できないアドレスは判定不能なので例外（fail-closed） */
    private function isDenied(string $ip, string $url): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new \RuntimeException("IPアドレスとして解釈できない宛先です（{$ip}）: {$url}");
        }

        $packed = inet_pton($ip);

        if ($packed === false) {
            throw new \RuntimeException("IPアドレスとして解釈できない宛先です（{$ip}）: {$url}");
        }

        $ranges = strlen($packed) === 4 ? self::DENIED_IPV4 : self::DENIED_IPV6;

        foreach ($ranges as $cidr) {
            [$network, $prefix] = explode('/', $cidr);

            if ($this->inRange($packed, (string) inet_pton($network), (int) $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** バイナリ表現のIPが network/prefix に含まれるか（前方のバイト比較＋端数ビットのマスク比較） */
    private function inRange(string $packed, string $network, int $prefix): bool
    {
        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($wholeBytes > 0 && strncmp($packed, $network, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainingBits) & 0xFF;

        return (ord($packed[$wholeBytes]) & $mask) === (ord($network[$wholeBytes]) & $mask);
    }
}
