<?php

namespace App\Http\Controllers;

use App\Models\GoogleCredential;
use App\Services\Google\GoogleOAuthClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Gmail 取得のための一度きりの認可。画面ログイン機能ではない。
 */
class GoogleAuthController extends Controller
{
    private const STATE_KEY = 'google_oauth_state';

    public function redirect(Request $request, GoogleOAuthClient $oauth): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, $state);

        return redirect()->away($oauth->authorizationUrl($state));
    }

    public function callback(Request $request, GoogleOAuthClient $oauth): RedirectResponse
    {
        // state 不一致は握り潰さず弾く（別サイトから認可コードを押し込まれるのを防ぐ）
        $expected = $request->session()->pull(self::STATE_KEY);

        if (blank($expected) || $request->query('state') !== $expected) {
            return redirect()->route('settings.mail')
                ->withErrors(['google' => '認可のstateが一致しませんでした。設定画面からやり直してください。']);
        }

        if (filled($request->query('error'))) {
            return redirect()->route('settings.mail')
                ->withErrors(['google' => 'Google 側で認可が拒否されました: '.$request->query('error')]);
        }

        $oauth->exchangeCode((string) $request->query('code'));

        return redirect()->route('settings.mail');
    }

    /**
     * 連携解除。Google 側でトークンを失効させてから手元の行を消す。
     * 失効できなかったときは「解除済み」に見せず、理由と手動の取り消し先を出して行を残す。
     */
    public function destroy(GoogleOAuthClient $oauth): RedirectResponse
    {
        $credential = GoogleCredential::query()->first();

        if ($credential === null) {
            return back();
        }

        try {
            $oauth->revoke($credential->refresh_token);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['google' => $e->getMessage()
                .' もう一度試すか、Google アカウントの「サードパーティ製のアプリとサービス」からこのアプリのアクセスを手動で取り消してください。']);
        }

        $credential->delete();

        return back();
    }
}
