<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ブラウザ拡張からのクリップ投入APIを、共有トークンで保護する（ローカル専用の簡易認証）。
 */
class VerifyClipToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.clip.token');
        $given = (string) $request->header('X-Clip-Token');

        if (! $expected || ! hash_equals((string) $expected, $given)) {
            return response()->json(['ok' => false, 'message' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
