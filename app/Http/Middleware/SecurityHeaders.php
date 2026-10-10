<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 最低限のセキュリティレスポンスヘッダを付与する。
 * カルテには口コミ等の機微な貼り付けデータが載るため、他サイトからの
 * フレーム埋め込み（クリックジャッキング）と、外部へのリファラ漏洩を塞ぐ。
 *
 * CSP は入れていない。Inertia + Vite の開発サーバがインラインスクリプトと
 * ws 接続を使うため、雑に入れると開発環境が壊れる。導入するなら nonce 付与と
 * dev/build のヘッダ出し分けをセットで設計する必要がある。
 */
class SecurityHeaders
{
    /** @var array<string, string> */
    private const HEADERS = [
        // 外部サイトからの iframe 埋め込みを禁止（クリックジャッキング対策）
        'X-Frame-Options' => 'DENY',
        // Content-Type の推測を止める（アップロード物の誤実行対策）
        'X-Content-Type-Options' => 'nosniff',
        // 外部サイトへはリファラを出さない（ローカル専用ツールなので外部へ URL を渡す必要が無い）。
        // 同じサイト内には送る。保存後の back() と入力エラーの戻り先は Referer で決まるため、
        // no-referrer だと画面内の遷移（Inertia の XHR）の後に、最後に直接開いた画面へ飛ばされる
        'Referrer-Policy' => 'same-origin',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
