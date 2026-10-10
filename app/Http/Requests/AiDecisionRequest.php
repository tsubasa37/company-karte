<?php

namespace App\Http\Requests;

use App\Enums\AiProvider;
use App\Services\Ai\AiSettings;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 判定エンジンの入力。provider が空なら機能オフ。選べるのは判定専用で API キーを .env に設定済みの実行先だけ
 * （それ以外を選ばせると、判定を呼んだ時点で例外で止まるだけになる）。
 */
class AiDecisionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(AiSettings $settings): array
    {
        $available = $settings->availableDecision();

        return [
            // キーの置き場所は .env だけ。この経路に混ぜて送らせない
            'api_key' => ['prohibited'],
            // only([]) は「絞らない」扱いで全 case を通すため、候補が無いときは全 case を除外する
            'provider' => ['nullable', $available === []
                ? Rule::enum(AiProvider::class)->except(AiProvider::cases())
                : Rule::enum(AiProvider::class)->only($available)],
            // オフで保存するときは使わない値なので検査しない（画面は無効化したモデル欄の値もそのまま送ってくる）
            'model' => [Rule::excludeIf(fn () => blank($this->input('provider'))), 'nullable', 'string', 'max:120'],
            // オフで保存したときは SaveAiDecisionAction が false に戻す
            'review_ordering' => ['sometimes', 'boolean'],
            // 同上（オフで保存したときは false に戻す）
            'send_interview_notes' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * モデル未設定のまま判定エンジンを選ばせない。
     * 判定専用の実行先には CLI の既定モデルが無く、保存できても判定を呼んだ時点で落ちるため、選ぶ時点で止める。
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (blank($this->input('provider')) || $validator->errors()->has('provider') || filled($this->input('model'))) {
                return;
            }

            $validator->errors()->add('model', '判定用モデルが未設定です。モデル名（例: jev-1.13.0）を入れてください。');
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'api_key.prohibited' => 'API キーはこの画面では扱いません（.env の TYPESAFE_API_KEY に書いてください）。',
            'provider.enum' => '判定エンジンに選べるのは判定専用で API キーを .env に設定済みの実行先だけです（TYPESAFE_API_KEY）。',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'provider' => '判定エンジン',
            'model' => '判定用モデル',
            'review_ordering' => '口コミの材料選抜に機械判定を使う',
            'send_interview_notes' => '面接メモを判定エンジンに送る',
        ];
    }
}
