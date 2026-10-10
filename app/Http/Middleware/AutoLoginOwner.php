<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * 自分専用ローカルツールのため、ログイン画面を介さず単一の既定ユーザーを自動ログインする。
 * データはユーザーに紐づかず、認証は UI を成立させるためだけに使う。
 */
class AutoLoginOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            $owner = User::firstOrCreate(
                ['email' => 'owner@local'],
                [
                    'name' => 'オーナー',
                    'password' => Hash::make(Str::random(40)),
                    'email_verified_at' => now(),
                ],
            );

            Auth::login($owner);
        }

        return $next($request);
    }
}
