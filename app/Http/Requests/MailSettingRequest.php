<?php

namespace App\Http\Requests;

use App\Models\MailSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Gmail連携の設定入力。値は Google Cloud の画面からコピペされる前提。
 */
class MailSettingRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Google が発行するクライアントIDは .apps.googleusercontent.com で終わる。
            // 貼り間違い（シークレットと取り違える等）をここで気づけるようにする
            'google_client_id' => ['required', 'string', 'max:255', 'ends_with:.apps.googleusercontent.com'],
            // シークレットは画面へ返さないため、登録済みなら空送信＝据え置きとして扱う
            // （ドメインだけ直したいときにシークレットを貼り直させない）
            'google_client_secret' => [
                Rule::requiredIf(blank(MailSetting::query()->value('google_client_secret'))),
                'nullable', 'string', 'max:255',
            ],
            'allowed_domains' => ['nullable', 'string', 'max:500'],
            'mask_words' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'google_client_id.ends_with' => 'クライアントIDの形式が違います（.apps.googleusercontent.com で終わる値を貼ってください）。'
                .'シークレットと取り違えていないか確認してください。',
        ];
    }
}
