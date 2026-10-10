<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ローカル専用ツールを守るため、接続元アドレスと Host ヘッダの両方を検証する。
 * AutoLoginOwner が全リクエストをオーナー化するため、ここを抜けられると
 * カルテ全体に到達できてしまう。
 *
 * - 接続元アドレス: 実際に TCP を張ってきた相手がループバックかを見る。
 *   php artisan serve --host=0.0.0.0 のような非ループバック束縛で起動された場合、
 *   同一LANの攻撃者は Host ヘッダを自由に詐称できるため、Host 照合だけでは通過してしまう。
 * - Host ヘッダ: DNS リバインディング対策。攻撃者ドメインを 127.0.0.1 に解決させると
 *   接続元はループバックになるため、接続元アドレスの検証だけでは防げない。
 *
 * どちらか一方では塞げないので両方必要。
 *
 * 標準の TrustHosts ミドルウェアは local 環境では無効化される
 * （shouldSpecifyTrustedHosts が local/テストで false）ため、local 前提の
 * 本ツールでは独自に Host を検証する。
 */
class EnsureLocalHost
{
    /** @var array<int, string> */
    private const ALLOWED_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

    /** @var array<int, string> */
    private const LOOPBACK_IPS = ['127.0.0.1', '::1'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isLoopback($request->ip())) {
            abort(403, 'company-karte はローカル専用ツールです。');
        }

        if (! in_array($request->getHost(), self::ALLOWED_HOSTS, true)) {
            abort(403, 'company-karte はローカル専用ツールです。');
        }

        if (! $this->portMatchesServer($request)) {
            abort(403, 'company-karte はローカル専用ツールです。');
        }

        return $next($request);
    }

    /**
     * Host ヘッダのポートが、実際に待ち受けているポートと一致するか。
     *
     * getHost() は ":8123" のようなポートを落とすため、ホスト名の照合だけでは
     * `Host: localhost:31337` が通ってしまう。route() は Host ヘッダからポート込みの
     * 絶対URLを組み立てるので、素通りさせると Gmail 連携の redirect_uri を
     * 攻撃者の待ち受けポートへ向けられる（GoogleOAuthClient::redirectUri）。
     *
     * 許可ポートを定数で持たないのは、起動方法（bin/dev.sh の 8123 / artisan serve の 8000）で
     * 変わる値をコード側で決め打ちしないため。実際の待ち受けポートと突き合わせれば設定は要らない。
     */
    private function portMatchesServer(Request $request): bool
    {
        $serverPort = $request->server->get('SERVER_PORT');

        // 待ち受けポートが分からない環境では判定材料が無いので、この検査は行わない
        // （接続元アドレスとホスト名の検証は上で済んでいる）
        if ($serverPort === null) {
            return true;
        }

        return (int) $request->getPort() === (int) $serverPort;
    }

    /**
     * 接続元がループバックか。
     * デュアルスタック束縛では ::ffff:127.0.0.1 のIPv4射影形式で届くことがあり、
     * 文字列比較のままだと正当なローカルアクセスを403にしてしまう。
     * 一方 ::ffff:192.168.1.1 のような射影された非ループバックは通してはならないため、
     * 前方一致で緩めず inet_pton で正規化し、射影を剥がした上で照合する。
     */
    private function isLoopback(?string $ip): bool
    {
        // 不正な値で inet_pton の警告（Laravel が ErrorException 化する）を出さないよう先に弾く
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $packed = inet_pton($ip);

        // IPv4射影は先頭80bitが0・続く16bitが ffff。末尾4バイトがIPv4本体。
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\x00", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
        }

        foreach (self::LOOPBACK_IPS as $loopback) {
            if ($packed === inet_pton($loopback)) {
                return true;
            }
        }

        return false;
    }
}
