<?php

namespace App\Actions;

use App\Enums\AiKind;
use App\Enums\AiProvider;
use App\Enums\AiTier;
use App\Models\AiProviderConfig;
use App\Models\AiSetting;
use App\Services\Ai\AiPricing;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ApiKeys;
use App\Services\Ai\Cost\SimulationInput;
use App\Services\Ai\Cost\SimulationTarget;
use App\Services\Ai\CostSimulator;
use App\Services\Ai\Driver\DriverRegistry;
use App\Services\Ai\ProviderOptionFields;
use Illuminate\Support\Collection;

/**
 * 設定画面に出す AI エンジンの状態（Tier ごとの実行先・候補・プロバイダごとの設定）。
 *
 * 秘密の値（API キー）は画面へ返さない。.env に設定済みかどうかと、その変数名だけを返す。
 * モデル未設定は例外にせず null で返す（画面は「未設定」と出す。実行時の例外は AiSettings が出す）。
 */
class BuildAiStatusAction
{
    public function __construct(
        private readonly AiSettings $settings,
        private readonly DriverRegistry $drivers,
        private readonly ProviderOptionFields $fields,
        private readonly ApiKeys $keys,
        private readonly AiPricing $pricing,
        private readonly CostSimulator $simulator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        // 行が無いと sole() が例外になり /settings ごと開けなくなる（画面から直す手段が消える）
        $setting = AiSetting::first() ?? throw new \RuntimeException(
            'AI エンジンの設定行（ai_settings）がありません。php artisan migrate を実行してください'
        );
        $configs = AiProviderConfig::all()->keyBy(fn (AiProviderConfig $config) => $config->provider->value);
        $available = $this->settings->available();
        $models = $this->modelOptions();

        return [
            'routing' => [
                AiTier::Analysis->value => $setting->analysis_provider->value,
                AiTier::Light->value => $setting->light_provider->value,
            ],
            'available' => array_map(fn (AiProvider $provider) => $provider->value, $available),
            'current' => [
                AiTier::Analysis->value => $this->current($setting->analysis_provider, AiTier::Analysis, $configs),
                AiTier::Light->value => $this->current($setting->light_provider, AiTier::Light, $configs),
            ],
            'providers' => array_map(
                fn (AiProvider $provider) => $this->provider($provider, $configs->get($provider->value), $models[$provider->value] ?? []),
                AiProvider::ofKind(AiKind::Generation),
            ),
            'decision' => $this->decision($setting, $configs),
        ];
    }

    /**
     * 判定エンジン（AiKind::Decision）の状態。Tier の実行先とは別枠で、既定は未設定（機能オフ）。
     *
     * @param  Collection<string, AiProviderConfig>  $configs
     * @return array{provider: string|null, reviewOrdering: bool, sendInterviewNotes: bool, available: array<int, string>, providers: array<int, array<string, mixed>>}
     */
    private function decision(AiSetting $setting, Collection $configs): array
    {
        return [
            'provider' => $this->settings->decisionProvider()?->value,
            // 裏取り（P2）の口コミを機械判定の関連度順に選ぶか
            'reviewOrdering' => $setting->decision_review_ordering,
            // 面接メモを判定エンジンに送る同意スイッチ
            'sendInterviewNotes' => $setting->decision_send_interview_notes,
            'available' => array_map(fn (AiProvider $provider) => $provider->value, $this->settings->availableDecision()),
            'providers' => array_map(fn (AiProvider $provider) => [
                'provider' => $provider->value,
                'label' => $provider->label(),
                'hasApiKey' => $this->keys->has($provider),
                'apiKeyEnvName' => $provider->apiKeyEnvName(),
                'modelDecision' => $configs->get($provider->value)?->model_decision,
                'modelPlaceholder' => $provider->modelPlaceholder(),
                // 判定は生成の作業量で1社あたりを見積もれないので、目安は付けず単価表の値だけを返す
                'models' => $this->pricing->models($provider),
                'docsUrl' => $provider->docsUrl(),
                'notes' => $this->fields->notesFor($provider),
                'timeout' => config("ai.providers.{$provider->value}.timeout"),
            ], AiProvider::ofKind(AiKind::Decision)),
        ];
    }

    /**
     * @param  Collection<string, AiProviderConfig>  $configs
     * @return array{provider: string, providerLabel: string, model: string|null}
     */
    private function current(AiProvider $provider, AiTier $tier, Collection $configs): array
    {
        $config = $configs->get($provider->value);
        $model = $tier === AiTier::Analysis ? $config?->model_analysis : $config?->model_light;

        return [
            'provider' => $provider->value,
            'providerLabel' => $provider->label(),
            'model' => filled($model) ? $model : null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $models  モデル選択の候補
     *
     * ドライバ未実装のプロバイダも表示する（モデルは先に入れておける）。
     * ただし実行先の候補（available）には入らないので、画面に driverReady を渡してその旨を出す。
     * @return array<string, mixed>
     */
    private function provider(AiProvider $provider, ?AiProviderConfig $config, array $models): array
    {
        return [
            'provider' => $provider->value,
            'label' => $provider->label(),
            'isSubprocess' => $provider->isSubprocess(),
            'hasApiKey' => $this->keys->has($provider),
            // キーを書く .env の変数名（codex は null）。画面は「どこに書くか」の案内に使う
            'apiKeyEnvName' => $provider->apiKeyEnvName(),
            'driverReady' => $this->drivers->supports($provider),
            'modelAnalysis' => $config?->model_analysis,
            'modelLight' => $config?->model_light,
            'modelPlaceholder' => $provider->modelPlaceholder(),
            // モデル選択の候補（単価表に載っているもの）。1社あたりの目安つきで安い順
            'models' => $models,
            'options' => $this->optionValues($provider, $config),
            'docsUrl' => $provider->docsUrl(),
            'fields' => $this->fields->fieldsFor($provider),
            'notes' => $this->fields->notesFor($provider),
        ];
    }

    /**
     * Tier ごとの固有オプションの現在値（フォームの初期値）。秘密ではないのでそのまま返す。
     *
     * 画面に出す項目の鍵を必ず全て埋める（未設定は空文字）。空でも JSON の配列にならないよう
     * オブジェクトにキャストし、画面側が options[tier][key] で引ける形を保証する。
     *
     * 数値項目は数値のまま返す。文字列にすると画面の v-model.number が書き戻した数値と型が食い違い、
     * ai_provider_configs.options に '0' と 0 が混ざる（0 / -1 が意味を持つ thinking_budget で効いてくる）。
     *
     * @return array<string, object>
     */
    private function optionValues(AiProvider $provider, ?AiProviderConfig $config): array
    {
        $stored = $config?->options ?? [];
        $fields = $this->fields->fieldsFor($provider);

        $values = [];
        foreach (AiTier::cases() as $tier) {
            $values[$tier->value] = (object) array_combine(
                array_column($fields, 'key'),
                array_map(
                    fn (array $field) => $this->optionValue($field, $stored[$tier->value][$field['key']] ?? null),
                    $fields,
                ),
            );
        }

        return $values;
    }

    /**
     * 項目1つの現在値。未設定は空文字（画面の「未設定」）で、数値項目だけ数値のまま返す。
     *
     * @param  array{key: string, type: string, ...}  $field
     */
    private function optionValue(array $field, mixed $value): string|int
    {
        if ($value === null || $value === '') {
            return '';
        }

        return $field['type'] === 'number' ? (int) $value : (string) $value;
    }

    /**
     * 単価表に載っているモデルを、1社あたりの費用の目安つきで返す（プロバイダ値 => 候補の配列）。
     *
     * 目安は料金シミュレーターに出させる。画面用に別の計算を持つと、同じ「1社いくら」が
     * 2か所で食い違いうるため（数値の確定は1か所という原則）。実測が無いうちは幅の低い方を使い、
     * 実測が貯まれば低め＝高めになって幅が消える。
     *
     * @return array<string, array<int, array{name: string, inputUsd: float, outputUsd: float, hasBands: bool, perCompanyMicroUsd: int|null, perCompanyJpy: int|null}>>
     */
    private function modelOptions(): array
    {
        $catalog = [];
        $targets = [];

        foreach (AiProvider::ofKind(AiKind::Generation) as $provider) {
            foreach ($this->pricing->models($provider) as $model) {
                $catalog[$provider->value][$model['name']] = $model + ['perCompanyMicroUsd' => null, 'perCompanyJpy' => null];
                $targets[] = new SimulationTarget($provider, $model['name']);
            }
        }

        if ($targets !== []) {
            foreach ($this->simulator->simulate(new SimulationInput($targets))->rows as $row) {
                $micro = $row->perCompanyMicroUsdLow;
                $entry = &$catalog[$row->target->provider->value][$row->target->model];
                $entry['perCompanyMicroUsd'] = $micro;
                // 円換算はレートを持つ PHP 側で確定させる（画面は表示するだけ）
                $entry['perCompanyJpy'] = $micro === null ? null : $this->pricing->toJpy($micro);
                unset($entry);
            }
        }

        // 安い順（費用を出せないものは末尾）
        return array_map(function (array $models) {
            uasort($models, fn (array $a, array $b) => ($a['perCompanyMicroUsd'] ?? PHP_INT_MAX) <=> ($b['perCompanyMicroUsd'] ?? PHP_INT_MAX));

            return array_values($models);
        }, $catalog);
    }
}
