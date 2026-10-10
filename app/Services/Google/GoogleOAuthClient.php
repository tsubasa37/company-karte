<?php

namespace App\Services\Google;

use App\Models\GoogleCredential;
use App\Models\MailSetting;
use Illuminate\Support\Facades\Http;

/**
 * Gmail 読み取りのための OAuth 2.0 認可コードフロー。
 *
 * このアプリに画面ログインは追加しない。一度きりの認可でリフレッシュトークンを得て、
 * 以降はそれでアクセストークンを更新し続ける。
 *
 * 注意: Google Cloud の公開ステータスが Testing のままだと、リフレッシュトークンが
 * 7日で失効する。自動取得を続けるには「本番（In production）」への切り替えが必須。
 */
class GoogleOAuthClient
{
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const USERINFO_ENDPOINT = 'https://www.googleapis.com/oauth2/v2/userinfo';

    private const REVOKE_ENDPOINT = 'https://oauth2.googleapis.com/revoke';

    /**
     * 読み取りのみ。既読化・ラベル付け・削除・送信はしないため書き込みスコープは要求しない。
     * gmail.metadata では検索クエリ(q)が使えず本文も取れないため readonly を使う。
     */
    private const SCOPES = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/userinfo.email',
    ];

    /** アクセストークンの期限切れ判定に持たせる余裕（秒） */
    private const EXPIRY_MARGIN = 60;

    public function isConfigured(): bool
    {
        $setting = MailSetting::query()->first();

        return filled($setting?->google_client_id) && filled($setting?->google_client_secret);
    }

    /**
     * 認可後に戻ってくる URL。ルート定義から組み立てる。
     *
     * 手入力にすると Google Cloud 側の登録値と1文字でも食い違った時点で認可が失敗し、
     * 原因が分かりにくい。ここで生成した値を設定画面に表示してコピーさせ、
     * 突き合わせる先を1つに保つ。
     */
    public function redirectUri(): string
    {
        return route('google.callback');
    }

    public function authorizationUrl(string $state): string
    {
        return self::AUTH_ENDPOINT.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            // リフレッシュトークンを受け取るために必須
            'access_type' => 'offline',
            // 再連携時にもリフレッシュトークンを確実に受け取る
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    /**
     * 認可コードをトークンに交換して保存する。
     *
     * @return GoogleCredential 保存済みの資格情報（常に1行）
     */
    public function exchangeCode(string $code): GoogleCredential
    {
        // 作り直しでは古いトークンを Google 側でも失効させてから消す（アプリから消すだけだと有効なまま残る）。
        // 新しい認可より先に行うのは、失効が新しい発行に巻き添えしないようにするため
        $old = GoogleCredential::query()->first();
        if ($old !== null) {
            $this->revoke($old->refresh_token);
            $old->delete();
        }

        $tokens = $this->post([
            'code' => $code,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ]);

        if (blank($tokens['refresh_token'] ?? null)) {
            throw new \RuntimeException(
                'Google がリフレッシュトークンを返しませんでした。'
                .'既存の連携を取り消してからやり直してください（prompt=consent で再要求します）。'
            );
        }

        // 常に1行だけ持つ（自分専用アプリのため）
        GoogleCredential::query()->delete();

        return GoogleCredential::create([
            'email' => $this->fetchEmail($tokens['access_token']),
            'refresh_token' => $tokens['refresh_token'],
            'access_token' => $tokens['access_token'],
            'access_token_expires_at' => now()->addSeconds((int) $tokens['expires_in']),
        ]);
    }

    /** 有効なアクセストークンを返す。期限が近ければリフレッシュしてから返す */
    public function accessToken(GoogleCredential $credential): string
    {
        $valid = filled($credential->access_token)
            && $credential->access_token_expires_at?->isAfter(now()->addSeconds(self::EXPIRY_MARGIN));

        if ($valid) {
            return $credential->access_token;
        }

        $tokens = $this->post([
            'refresh_token' => $credential->refresh_token,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'grant_type' => 'refresh_token',
        ]);

        $credential->update([
            'access_token' => $tokens['access_token'],
            'access_token_expires_at' => now()->addSeconds((int) $tokens['expires_in']),
        ]);

        return $tokens['access_token'];
    }

    /**
     * Google 側でトークンを失効させる。連携解除を「アプリから消した」だけで終えると、
     * バックアップやログから漏れたリフレッシュトークンが Gmail を読める状態で残るため。
     * 既に失効済み（invalid_token）は解除の目的を満たしているので成功扱い。それ以外の失敗は例外（握り潰さない）。
     */
    public function revoke(string $token): void
    {
        $response = Http::asForm()->post(self::REVOKE_ENDPOINT, ['token' => $token]);

        if ($response->successful()) {
            return;
        }

        if ($response->status() === 400 && $response->json('error') === 'invalid_token') {
            return;
        }

        throw new \RuntimeException(
            'Google 側のトークン取り消しに失敗しました（HTTP '.$response->status().'）: '.$response->body()
        );
    }

    /**
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    private function post(array $payload): array
    {
        $response = Http::asForm()->post(self::TOKEN_ENDPOINT, $payload);

        if ($response->failed()) {
            // invalid_grant はトークン失効（Testing のままだと7日で起きる）。原因が分かる形で残す
            throw new \RuntimeException(
                'Google トークン取得に失敗しました（HTTP '.$response->status().'）: '.$response->body()
            );
        }

        return $response->json();
    }

    /** どのアカウントに繋がっているかを画面に出すためだけに使う */
    private function fetchEmail(string $accessToken): ?string
    {
        $response = Http::withToken($accessToken)->get(self::USERINFO_ENDPOINT);

        if ($response->failed()) {
            throw new \RuntimeException(
                'Google アカウント情報の取得に失敗しました（HTTP '.$response->status().'）: '.$response->body()
            );
        }

        return $response->json('email');
    }

    private function clientId(): string
    {
        $id = MailSetting::query()->value('google_client_id');
        if (blank($id)) {
            throw new \RuntimeException('クライアントIDが未設定です。設定画面のGmail連携から登録してください。');
        }

        return $id;
    }

    private function clientSecret(): string
    {
        $secret = MailSetting::query()->value('google_client_secret');
        if (blank($secret)) {
            throw new \RuntimeException('クライアントシークレットが未設定です。設定画面のGmail連携から登録してください。');
        }

        return $secret;
    }
}
