<?php

use App\Http\Middleware\AutoLoginOwner;
use App\Http\Middleware\EnsureLocalHost;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyClipToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ローカル専用: 全リクエストで接続元アドレスと Host ヘッダを検証する。
        // SecurityHeaders を EnsureLocalHost より外側に置くのは、web グループ限定だと
        // /api/*・/up・EnsureLocalHost の 403 にヘッダが付かなかったため。
        // Routing\Pipeline は各スライスの例外を応答へ変換してから外側へ返すので、
        // 内側で abort(403) しても外側のこのミドルウェアがヘッダを付けられる。
        $middleware->prepend([
            SecurityHeaders::class,
            EnsureLocalHost::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AutoLoginOwner::class,
        ]);

        $middleware->alias([
            'clip.token' => VerifyClipToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // バリデーション失敗時、入力値はセッションへ書き戻される（old input）。
        // セッションは database ドライバのため、暗号化していなければ DB 上に平文で残る。
        // mail_settings で暗号化して保存している値（クライアントID・シークレット・伏せ字＝本人の氏名）が
        // この経路で素通りしてしまうので除外する（Gmail 設定は4項目を1回で送るため、
        // クライアントIDの形式違いだけで残り全部が old input に落ちる）。
        // api_key は画面から送らせない（FormRequest で prohibited）が、拒否した入力も
        // old input として残る。手動 PUT や古いタブから送られた場合に備えて、値そのものを残さない
        $exceptions->dontFlash(['google_client_id', 'google_client_secret', 'mask_words', 'api_key']);
    })->create();
