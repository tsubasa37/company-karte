<?php

namespace App\Actions;

use App\Models\MailSetting;

/**
 * Gmail連携の設定を保存する（自分専用アプリなので常に1行を作り直す形で更新）。
 * 秘密の値はモデルのキャストで暗号化される。
 */
class SaveMailSettingAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data): MailSetting
    {
        $setting = MailSetting::query()->first();

        if ($setting === null) {
            return MailSetting::create($data);
        }

        // 空のシークレットは「据え置き」を意味する（画面へ返していないため貼り直しを強いない）。
        // 消したい場合は連携解除ではなくクライアント自体を登録し直す運用にする
        if (blank($data['google_client_secret'] ?? null)) {
            unset($data['google_client_secret']);
        }

        $setting->update($data);

        return $setting;
    }
}
