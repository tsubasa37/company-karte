<?php

namespace App\Services\Mail;

use App\Models\GoogleCredential;
use App\Models\MailSetting;

/**
 * メールの自動取得（15分おき）が有効かどうかの判定を1か所に集める。
 *
 * スケジューラの実行可否と、設定画面の「押していなくても走る」という説明文が
 * 同じ条件を見るようにするため（片方だけ直して説明と実態がずれるのを防ぐ）。
 */
class MailAutoFetch
{
    public function __construct(private readonly FromDomainAllowList $allowList) {}

    /** 人が画面で「自動」を選んでいるか。スケジューラの実行可否はこれで決まる */
    public function isEnabled(): bool
    {
        return (bool) MailSetting::query()->value('auto_fetch');
    }

    /**
     * 実際に取得が走る状態か（自動が選ばれ、連携済みで、許可ドメインもある）。
     * どれか欠けると FetchMailAction が例外で止まり、AI は1回も呼ばれない。
     */
    public function isActive(): bool
    {
        return $this->isEnabled()
            && GoogleCredential::query()->exists()
            && $this->allowList->isConfigured();
    }
}
