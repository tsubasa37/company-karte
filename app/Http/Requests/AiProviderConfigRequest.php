<?php

namespace App\Http\Requests;

use App\Enums\AiProvider;
use App\Enums\AiTier;
use App\Services\Ai\ProviderOptionFields;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * プロバイダごとの接続設定の入力。provider はルートパラメータ（AiProvider に enum バインド済み）。
 *
 * - API キーは .env が置き場所なので、この経路では受け付けない（送られてきたら拒否）
 * - options は画面に出している鍵（ProviderOptionFields）だけ。それ以外は拒否
 * - 同時に指定できない推論オプション（世代違い・モードと引数の食い違い）はここで落とす
 */
class AiProviderConfigRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(ProviderOptionFields $fields): array
    {
        $provider = $this->provider();
        $optionFields = $fields->fieldsFor($provider);

        $rules = [
            // キーの置き場所は .env だけ。この経路に混ぜて送らせない
            'api_key' => ['prohibited'],
            'model_analysis' => ['nullable', 'string', 'max:120'],
            'model_light' => ['nullable', 'string', 'max:120'],
            'options' => ['nullable', 'array:'.implode(',', array_map(fn (AiTier $tier) => $tier->value, AiTier::cases()))],
        ];

        foreach (AiTier::cases() as $tier) {
            // 固有オプションの無いプロバイダは空の配列だけ受ける
            $rules["options.{$tier->value}"] = $optionFields === []
                ? ['nullable', 'array', 'max:0']
                : ['nullable', 'array:'.implode(',', $fields->keysFor($provider))];
            foreach ($optionFields as $field) {
                $rules["options.{$tier->value}.{$field['key']}"] = $this->fieldRules($field);
            }
        }

        return $rules;
    }

    /**
     * 1項目では見えない食い違い（同じ Tier の中で同時に指定できない組合せ）を落とす。
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (AiTier::cases() as $tier) {
                $this->assertExclusive($validator, $tier);
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $label = $this->provider()->label();

        return [
            'api_key.prohibited' => 'API キーはこの画面では扱いません（.env の '
                .($this->provider()->apiKeyEnvName() ?? '該当なし').' に書いてください）。',
            'options.array' => '固有オプションの形が違います。画面のフォームから保存してください。',
            'options.*.array' => "{$label} に無い固有オプションが含まれています。画面のフォームから保存してください。",
            'options.*.max' => "{$label} に固有オプションはありません。",
            'options.*.*.in' => '選択肢に無い値です。',
            'options.*.*.integer' => '整数で入力してください。',
            'options.*.*.min' => ':min 以上の整数で入力してください。',
            'options.*.*.max' => ':max 以下の整数で入力してください。',
        ];
    }

    /**
     * 項目の型ごとの検証規則。未知の型は黙って素通しせず落とす（画面と検証が食い違ったまま保存させない）。
     *
     * @param  array{key: string, type: string, choices: array<int, array{value: string, label: string}>, min: int|null, max: int|null}  $field
     * @return array<int, mixed>
     */
    private function fieldRules(array $field): array
    {
        return match ($field['type']) {
            'select' => ['nullable', 'string', Rule::in(array_column($field['choices'], 'value'))],
            // min / max が null なのは config の不備で範囲を出せなかったときだけ。
            // 'min:' のような壊れた規則を組み立てず範囲だけ外す（実行時にドライバが例外で止める）
            'number' => array_values(array_filter([
                'nullable',
                'integer',
                $field['min'] === null ? null : 'min:'.$field['min'],
                $field['max'] === null ? null : 'max:'.$field['max'],
            ])),
            default => throw new \LogicException("固有オプションの型 {$field['type']} に対応する検証規則がありません"),
        };
    }

    /**
     * 同時に指定できない推論オプションを Tier ごとに確かめる。
     * ドライバ側でも例外にしているが、保存できてしまうと実行するまで気づけないのでここで止める。
     */
    private function assertExclusive(Validator $validator, AiTier $tier): void
    {
        $options = $this->input("options.{$tier->value}", []);
        if (! is_array($options)) {
            return;
        }

        $add = function (string $key, string $message) use ($validator, $tier): void {
            $validator->errors()->add("options.{$tier->value}.{$key}", "{$tier->label()}：{$message}");
        };

        match ($this->provider()) {
            // thinkingLevel は Gemini 3 系・thinkingBudget は 2.5 系。片方を黙って選ばない
            AiProvider::Gemini => filled($options['thinking_level'] ?? null) && filled($options['thinking_budget'] ?? null)
                ? $add('thinking_budget', '思考の深さ（3 系）と思考の予算（2.5 系）は同時に指定できません。どちらか一方にしてください。')
                : null,
            AiProvider::Anthropic => $this->assertAnthropicThinking($options, $add),
            default => null,
        };
    }

    /**
     * 拡張思考のモードと引数の噛み合わせ。adaptive は effort（4.6 以降）、enabled は budget_tokens（4.6 以前）で、
     * 取り違えると API が 400 を返す（ドライバも同じ条件で落とす）。モードの値そのものは Rule::in が見る。
     *
     * @param  array<string, mixed>  $options
     * @param  \Closure(string, string): void  $add
     */
    private function assertAnthropicThinking(array $options, \Closure $add): void
    {
        $mode = filled($options['thinking_mode'] ?? null) ? (string) $options['thinking_mode'] : null;
        $hasEffort = filled($options['effort'] ?? null);
        $hasBudget = filled($options['budget_tokens'] ?? null);

        if ($mode === null) {
            if ($hasEffort) {
                $add('effort', '推論の強さは拡張思考のモードを adaptive にしたときだけ指定できます。');
            }
            if ($hasBudget) {
                $add('budget_tokens', '思考の予算は拡張思考のモードを enabled にしたときだけ指定できます。');
            }

            return;
        }

        if ($mode === 'adaptive' && $hasBudget) {
            $add('budget_tokens', '思考の予算は enabled（4.6 以前）専用です。adaptive では推論の強さで指定してください。');
        }

        if ($mode === 'enabled' && $hasEffort) {
            $add('effort', '推論の強さは adaptive（4.6 以降）専用です。enabled では思考の予算で指定してください。');
        }

        if ($mode === 'enabled' && ! $hasBudget) {
            $add('budget_tokens', 'enabled では思考の予算（1024 以上・max_tokens 未満）の指定が必要です。');
        }
    }

    private function provider(): AiProvider
    {
        $provider = $this->route('provider');

        if (! $provider instanceof AiProvider) {
            throw new \LogicException('ルートパラメータ provider が AiProvider にバインドされていません');
        }

        return $provider;
    }
}
