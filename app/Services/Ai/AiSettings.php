<?php

namespace App\Services\Ai;

use App\Enums\AiKind;
use App\Enums\AiProvider;
use App\Enums\AiTier;
use App\Models\AiProviderConfig;
use App\Models\AiSetting;
use App\Services\Ai\Decision\DecisionDriverRegistry;
use App\Services\Ai\Decision\DecisionEngineNotConfigured;
use App\Services\Ai\Driver\DriverRegistry;

/**
 * 実行先の解決役。ai_settings（Tier → プロバイダ）と ai_provider_configs（プロバイダごとの
 * モデル・固有オプション）から、1回分の実行先 AiTarget を組む。
 * API キーは .env が唯一の置き場所で、読み取りは ApiKeys に任せる。
 *
 * キー未設定・モデル未設定は API 側のエラーを待たず、ここで日本語の例外にして止める（自動で別の実行先に落とさない）。
 */
class AiSettings
{
    public function __construct(
        private readonly DriverRegistry $drivers,
        private readonly ApiKeys $keys,
        private readonly DecisionDriverRegistry $decisionDrivers,
    ) {}

    public function resolve(AiTier $tier): AiTarget
    {
        $provider = $this->providerFor($tier);
        $config = $this->configFor($provider);
        $model = $this->modelOf($config, $tier);

        if ($model === null && ! $provider->isSubprocess()) {
            throw new \RuntimeException("{$provider->label()} の{$tier->label()}用モデルが未設定です。設定画面から登録してください");
        }

        return new AiTarget($provider, $model, $this->optionsOf($config, $tier), $this->timeoutOf($provider));
    }

    /**
     * 接続テスト用の実行先。数トークンで済ませたいので抽出・判定用モデルを使い、空なら分析・要約用を使う。
     */
    public function probeTarget(AiProvider $provider): AiTarget
    {
        $config = $this->configFor($provider);
        $tier = $this->modelOf($config, AiTier::Light) !== null ? AiTier::Light : AiTier::Analysis;
        $model = $this->modelOf($config, $tier);

        if ($model === null && ! $provider->isSubprocess()) {
            throw new \RuntimeException("{$provider->label()} のモデルが未設定です。設定画面から登録してください");
        }

        return new AiTarget($provider, $model, $this->optionsOf($config, $tier), $this->timeoutOf($provider));
    }

    /**
     * 今使えるプロバイダ（Tier セレクトの候補）。ドライバが実装済みで、かつ
     * codex（キー不要）か .env にキーが設定済みのもの。
     *
     * 選べるのに実行すると必ず落ちる状態を作らないため、ドライバ未実装は候補に入れない。
     *
     * @return array<int, AiProvider>
     */
    public function available(): array
    {
        return array_values(array_filter(
            AiProvider::cases(),
            // 判定専用の実行先は Tier の実行先にしない
            fn (AiProvider $provider) => $provider->kind() === AiKind::Generation
                && $this->drivers->supports($provider)
                && ($provider->isSubprocess() || $this->keys->has($provider)),
        ));
    }

    /**
     * 判定エンジンに選べる実行先。判定専用で、判定ドライバが実装済みで、かつ .env にキーが設定済みのもの。
     *
     * @return array<int, AiProvider>
     */
    public function availableDecision(): array
    {
        return array_values(array_filter(
            AiProvider::ofKind(AiKind::Decision),
            fn (AiProvider $provider) => $this->decisionDrivers->supports($provider) && $this->keys->has($provider),
        ));
    }

    /** 今選ばれている判定エンジン。null は機能オフ */
    public function decisionProvider(): ?AiProvider
    {
        return AiSetting::sole()->decision_provider;
    }

    public function isDecisionConfigured(): bool
    {
        return $this->decisionProvider() !== null;
    }

    /** 裏取り（P2）の口コミを機械判定の関連度順に選ぶか（判定エンジンの設定のスイッチ） */
    public function isDecisionReviewOrderingEnabled(): bool
    {
        return AiSetting::sole()->decision_review_ordering;
    }

    /** 面接メモを判定エンジンに送り、論点の消し込みを機械判定にするか（判定エンジン設定済み かつ 同意スイッチがオン） */
    public function isDecisionInterviewNotesAllowed(): bool
    {
        $setting = AiSetting::sole();

        return $setting->decision_provider !== null && $setting->decision_send_interview_notes;
    }

    /**
     * 今選ばれている判定エンジンの、1回あたりの上限秒（ジョブの締め切り算出用）。未設定（オフ）なら 0。
     * キーの有無は見ない（締め切りの見積もりで実行可否を判定しない）。
     */
    public function decisionTimeout(): int
    {
        $provider = $this->decisionProvider();

        return $provider === null ? 0 : $this->timeoutOf($provider);
    }

    /** 判定用モデルが決まっているか（判定専用の実行先は CLI の既定モデルが無いので常に必須） */
    public function hasDecisionModel(AiProvider $provider): bool
    {
        return filled(AiProviderConfig::where('provider', $provider->value)->value('model_decision'));
    }

    /**
     * 判定 1 回分の実行先。未設定・キー無し・モデル無しは日本語の例外で止める（従来の挙動に黙って戻さない）。
     */
    public function resolveDecision(): AiTarget
    {
        $provider = $this->decisionProvider() ?? throw new DecisionEngineNotConfigured;

        // キーが無ければ実行しない（変数名を含む文言は ApiKeys が出す）
        $this->keys->for($provider);

        $model = AiProviderConfig::where('provider', $provider->value)->value('model_decision');

        if (blank($model)) {
            throw new \RuntimeException("{$provider->label()} の判定用モデルが未設定です。設定画面から登録してください");
        }

        return new AiTarget($provider, (string) $model, [], $this->timeoutOf($provider));
    }

    /**
     * その Tier で使うモデルが決まっているか。
     * codex は空欄でも CLI の既定モデルで動くので常に true。
     */
    public function hasModelFor(AiProvider $provider, AiTier $tier): bool
    {
        if ($provider->isSubprocess()) {
            return true;
        }

        $config = AiProviderConfig::where('provider', $provider->value)->first();

        return $config !== null && $this->modelOf($config, $tier) !== null;
    }

    /**
     * その Tier に今選ばれているプロバイダの、1回あたりの上限秒（ジョブの締め切り算出用）。
     * キーの有無は見ない（締め切りの見積もりで実行可否を判定しない）。
     */
    public function timeoutFor(AiTier $tier): int
    {
        return $this->timeoutOf($this->providerFor($tier));
    }

    private function providerFor(AiTier $tier): AiProvider
    {
        $setting = AiSetting::sole();

        return match ($tier) {
            AiTier::Analysis => $setting->analysis_provider,
            AiTier::Light => $setting->light_provider,
        };
    }

    private function configFor(AiProvider $provider): AiProviderConfig
    {
        if (! $provider->isSubprocess()) {
            // キーが無ければ実行しない（変数名を含む文言は ApiKeys が出す）
            $this->keys->for($provider);
        }

        $config = AiProviderConfig::where('provider', $provider->value)->first();

        if ($config !== null) {
            return $config;
        }

        if ($provider->isSubprocess()) {
            // codex 行は migration が必ず作る。無いのは移行漏れなので止める
            throw new \RuntimeException("{$provider->label()} の設定行（ai_provider_configs）がありません。php artisan migrate を実行してください");
        }

        // API 系は「まだ一度も保存していない」だけ。モデル未設定として扱い、
        // どの Tier のモデルが要るかを resolve() 側の文言で伝える
        return new AiProviderConfig(['provider' => $provider->value]);
    }

    private function modelOf(AiProviderConfig $config, AiTier $tier): ?string
    {
        $model = match ($tier) {
            AiTier::Analysis => $config->model_analysis,
            AiTier::Light => $config->model_light,
        };

        return filled($model) ? $model : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function optionsOf(AiProviderConfig $config, AiTier $tier): array
    {
        $options = $config->options[$tier->value] ?? [];

        // 画面から保存する限り配列になるが、DB を直接編集した場合に TypeError で落ちるのを防ぐ
        if (! is_array($options)) {
            throw new \RuntimeException(
                "ai_provider_configs.options の {$tier->value} が壊れています（配列である必要があります）。設定画面から保存し直してください"
            );
        }

        return $options;
    }

    private function timeoutOf(AiProvider $provider): int
    {
        $timeout = $provider->isSubprocess()
            ? config('services.codex.timeout')
            : config("ai.providers.{$provider->value}.timeout");

        if (! is_numeric($timeout) || (int) $timeout <= 0) {
            throw new \RuntimeException("{$provider->label()} の timeout が設定されていません");
        }

        return (int) $timeout;
    }
}
