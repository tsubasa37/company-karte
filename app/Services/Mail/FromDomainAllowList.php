<?php

namespace App\Services\Mail;

use App\Models\MailSetting;

/**
 * 取り込む差出人ドメインの許可リスト。
 *
 * Gmail 側のクエリで絞った上で、取得後にもう一度ここで確認する
 * （クエリの書き間違いで想定外のメールが入るのを防ぐ二重の歯止め）。
 * 未設定なら1通も取り込まない（既定で全部読む、という状態を作らない）。
 */
class FromDomainAllowList
{
    /**
     * @return array<int, string>
     */
    public function domains(): array
    {
        $configured = MailSetting::query()->value('allowed_domains');

        return collect(explode(',', (string) $configured))
            ->map(fn (string $d) => mb_strtolower(trim(ltrim($d, '@'))))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function isConfigured(): bool
    {
        return $this->domains() !== [];
    }

    /** サブドメイン（scout.example.com）も許可ドメイン（example.com）の一致として扱う */
    public function allows(string $domain): bool
    {
        $domain = mb_strtolower(trim($domain));

        foreach ($this->domains() as $allowed) {
            if ($domain === $allowed || str_ends_with($domain, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /** Gmail 検索クエリの from 条件。該当外はそもそも取得しない */
    public function gmailQuery(): string
    {
        $terms = array_map(fn (string $d) => '@'.$d, $this->domains());

        return 'from:('.implode(' OR ', $terms).')';
    }
}
