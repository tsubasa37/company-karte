<?php

namespace App\Services;

/**
 * ホスト名を IP アドレス（A/AAAA）へ解決する。
 * PublicUrlGuard から切り出しているのは、テストで実DNSを引かせないため（DIで差し替える）。
 */
class DnsResolver
{
    /**
     * @return array<int, string> 解決した IP アドレス。解決できなければ空配列（呼び出し側で拒否する）
     */
    public function resolve(string $host): array
    {
        // 解決失敗時の warning は Laravel が ErrorException 化してしまい「取得を拒否した」意図が
        // 伝わらないため抑止する。空配列を返して PublicUrlGuard 側で例外にする（fail-closed）。
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $ips = [];

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip) && $ip !== '') {
                $ips[] = $ip;
            }
        }

        return $ips;
    }
}
