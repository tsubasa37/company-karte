<?php

namespace App\Http\Requests;

use App\Enums\AiProvider;
use App\Enums\AiTier;
use App\Services\Ai\AiSettings;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tier ごとの実行先の入力。選べるのはドライバ実装済みで、codex か API キーを .env に設定済みのプロバイダだけ
 * （それ以外を選ばせると、実行時に例外で止まるだけになる）。
 */
class AiRoutingRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(AiSettings $settings): array
    {
        $available = $settings->available();

        return [
            'analysis_provider' => ['required', Rule::enum(AiProvider::class)->only($available)],
            'light_provider' => ['required', Rule::enum(AiProvider::class)->only($available)],
        ];
    }

    /**
     * モデル未設定の実行先を選ばせない。
     *
     * キーとドライバが揃っていてもモデルが空だと、保存はできるのに分析を押した時点で
     * 「モデルが未設定です」で落ちる。押すまで気づけないので、選ぶ時点で止める
     * （codex は空欄なら CLI の既定モデルで動くため対象外）。
     *
     * @return array<int, callable>
     */
    public function after(AiSettings $settings): array
    {
        return [function (Validator $validator) use ($settings): void {
            foreach ([[AiTier::Analysis, 'analysis_provider'], [AiTier::Light, 'light_provider']] as [$tier, $field]) {
                $value = $this->input($field);

                if (! is_string($value) || $validator->errors()->has($field)) {
                    continue;
                }

                $provider = AiProvider::tryFrom($value);

                if ($provider === null || $provider->isSubprocess() || $settings->hasModelFor($provider, $tier)) {
                    continue;
                }

                $validator->errors()->add(
                    $field,
                    "{$provider->label()} の{$tier->label()}用モデルが未設定です。下の接続設定でモデルを選んでから実行先にしてください。",
                );
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $message = '選べる実行先は codex と、ドライバ実装済みで API キーを設定済みのプロバイダだけです。先に .env にキーを書いてください（変数名は下の接続設定に出ています）。';

        return [
            'analysis_provider.enum' => $message,
            'light_provider.enum' => $message,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'analysis_provider' => '分析・要約の実行先',
            'light_provider' => '抽出・判定の実行先',
        ];
    }
}
