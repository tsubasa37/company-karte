<?php

namespace App\Actions;

use App\Models\GoogleCredential;
use App\Models\MailSetting;
use App\Services\Google\GoogleOAuthClient;
use App\Services\Mail\FromDomainAllowList;

/**
 * 設定画面に出す Gmail 連携の状態。
 * 「繋がっていない理由」を必ず出す（クライアント未登録なのか未連携なのかを区別できるようにする）。
 *
 * 秘密の値（シークレット・伏せ字）は画面へ返さない。設定済みかどうかだけを返す。
 */
class BuildGmailStatusAction
{
    public function __construct(
        private readonly GoogleOAuthClient $oauth,
        private readonly FromDomainAllowList $allowList,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $credential = GoogleCredential::query()->first();
        $setting = MailSetting::query()->first();

        return [
            // クライアントIDは貼り直しの目印になるので画面に戻す（シークレットは返さない）
            'clientId' => $setting?->google_client_id,
            'hasClientSecret' => filled($setting?->google_client_secret),
            'configured' => $this->oauth->isConfigured(),
            'connected' => $credential !== null,
            // 取り込み方法。false（手動）の間はスケジューラが1回も走らない
            'autoFetch' => (bool) $setting?->auto_fetch,
            'email' => $credential?->email,
            'lastFetchedAt' => $credential?->last_fetched_at?->format('Y-m-d H:i'),
            // 直近の取得失敗（成功すると消える）。スケジューラの失敗を画面から分かるようにする
            'lastFetchError' => $credential?->last_fetch_error,
            'lastFetchErrorAt' => $credential?->last_fetch_error_at?->format('Y-m-d H:i'),
            // Google Cloud 側へ登録してもらう値。手入力させず、ここで生成したものをコピーさせる
            'redirectUri' => $this->oauth->redirectUri(),
            'allowedDomains' => $this->allowList->domains(),
            'allowedDomainsText' => (string) $setting?->allowed_domains,
            // 伏せ字は本人自身の氏名で、編集のたびに入れ直させる意味がないため画面へ戻す。
            // シークレットだけは戻さない（他人に渡ると Google 側で使われうる真の秘密のため）
            'maskWordsText' => (string) $setting?->mask_words,
            'windowDays' => (int) config('services.mail_ingest.window_days'),
            'maxMessages' => (int) config('services.mail_ingest.max_messages'),
        ];
    }
}
