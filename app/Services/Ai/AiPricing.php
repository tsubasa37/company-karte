<?php

namespace App\Services\Ai;

use App\Enums\AiProvider;
use App\Services\Ai\Driver\TokenUsage;

/**
 * 単価表（config/ai.php の pricing）から AI 実行1回分の推定費用をマイクロ USD の整数で出す。
 *
 * 式: (input − cached − cacheWrite) × p_in + cached × p_cached + cacheWrite × p_cache_write + output × p_out
 * reasoning は output の内数なので式に出ない。単価は USD / 1M tokens なので「トークン数 × 単価」がそのままマイクロ USD になる。
 * 単価未登録・モデル不明・トークン不明は null（0 で埋めない）。codex はサブスクなので常に null。
 * 単価表の形が不正なら例外（黙って 0 や別の帯で計算しない）。
 */
class AiPricing
{
    private const REQUIRED_RATES = ['input', 'cached_input', 'output'];

    public function costMicroUsd(AiProvider $provider, ?string $model, ?TokenUsage $tokens): ?int
    {
        if ($provider->isSubprocess() || $model === null || $tokens === null) {
            return null;
        }

        if ($tokens->input === null || $tokens->output === null) {
            return null;
        }

        // モデル名に "." が入る（gpt-5.6-sol 等）ため、config のドット記法で直接引かず表を取ってから添字で引く
        $entry = config("ai.pricing.{$provider->value}")[$model] ?? null;
        if ($entry === null) {
            return null;
        }

        $label = "ai.pricing.{$provider->value}.{$model}";
        $rates = $this->ratesFor($entry, $tokens->input, $label);

        $cached = $tokens->cached ?? 0;
        // cache_write 単価が無いモデルは書き込み分も通常の入力単価（input の内数のまま引かない）
        $cacheWrite = array_key_exists('cache_write', $rates) ? ($tokens->cacheWrite ?? 0) : 0;
        $plain = $tokens->input - $cached - $cacheWrite;

        if ($plain < 0) {
            throw new \RuntimeException("{$label} の計算でキャッシュ分のトークンが総入力を超えています（ドライバの正規化が壊れています）");
        }

        $micro = $plain * $rates['input']
            + $cached * $rates['cached_input']
            + $cacheWrite * ($rates['cache_write'] ?? 0)
            + $tokens->output * $rates['output'];

        return (int) round($micro);
    }

    /**
     * マイクロ USD を円に換算する。レートは設定値。
     *
     * 換算が要る場所（設定画面のモデル選択・見積もりコマンド）で別々に読むと、
     * 片方だけ検証が抜けて「1社あたり 0 円」＝無料に読める表示になりうるので1本にする。
     *
     * @throws \RuntimeException レートが不正なとき（黙って 0 円と出さない）
     */
    public function toJpy(int $microUsd): int
    {
        return (int) round($microUsd / 1_000_000 * $this->jpyPerUsd());
    }

    /**
     * 1 USD が何円か。表示に添える用（「1 USD = 158.93円で換算」）。
     *
     * @throws \RuntimeException レートが不正なとき。壊れていると 0 円＝無料に読めるので黙って通さない
     */
    public function jpyPerUsd(): float
    {
        $rate = config('ai.estimate.jpy_per_usd');

        if (! is_numeric($rate) || (float) $rate <= 0) {
            throw new \RuntimeException('config/ai.php の estimate.jpy_per_usd が不正です（AI_ESTIMATE_JPY_PER_USD を確認してください）');
        }

        return (float) $rate;
    }

    /**
     * 単価表に載っているモデル（設定画面のモデル選択の候補）。
     *
     * 単価は 100万トークンあたりの USD。段階単価のモデルは最初の帯（通常の長さで当たる方）を返し、
     * 長文で単価が上がることは hasBands で伝える。codex はサブスクで単価表を持たないので空。
     *
     * @return array<int, array{name: string, inputUsd: float, outputUsd: float, hasBands: bool}>
     */
    public function models(AiProvider $provider): array
    {
        $models = [];

        foreach ((array) config("ai.pricing.{$provider->value}") as $name => $entry) {
            $label = "ai.pricing.{$provider->value}.{$name}";

            // 単価表の1行が壊れていたら候補から黙って外さず例外にする（外すと「なぜ選べないか」が
            // 画面から分からない）。単価表は同梱の設定ファイルで、形はテストが担保する
            // 入力 0 トークンは必ず最初の帯に当たる（帯の判定は入力総量 <= up_to）
            $rates = $this->ratesFor($entry, 0, $label);

            $models[] = [
                'name' => (string) $name,
                'inputUsd' => (float) $rates['input'],
                'outputUsd' => (float) $rates['output'],
                'hasBands' => is_array($entry) && array_key_exists('bands', $entry),
            ];
        }

        return $models;
    }

    /**
     * 単価の組を選ぶ。段階単価（bands）は入力総量 <= up_to を満たす最初の帯（up_to が null の帯は無条件）。
     *
     * @return array<string, float|int>
     */
    private function ratesFor(mixed $entry, int $inputTokens, string $label): array
    {
        if (! is_array($entry)) {
            throw new \RuntimeException("{$label} の単価は配列で書いてください");
        }

        if (! array_key_exists('bands', $entry)) {
            return $this->assertRates($entry, $label);
        }

        foreach ($entry['bands'] as $i => $band) {
            if (! is_array($band) || ! array_key_exists('up_to', $band)) {
                throw new \RuntimeException("{$label}.bands.{$i} に up_to がありません（無制限の帯は up_to => null）");
            }

            if ($band['up_to'] === null || $inputTokens <= (int) $band['up_to']) {
                return $this->assertRates($band, "{$label}.bands.{$i}");
            }
        }

        throw new \RuntimeException("{$label}.bands に入力 {$inputTokens} トークンを受ける帯がありません（最後は up_to => null にしてください）");
    }

    /**
     * @param  array<string, mixed>  $rates
     * @return array<string, float|int>
     */
    private function assertRates(array $rates, string $label): array
    {
        foreach (self::REQUIRED_RATES as $key) {
            if (! isset($rates[$key]) || ! is_numeric($rates[$key])) {
                throw new \RuntimeException("{$label} に {$key} の単価がありません");
            }
        }

        if (array_key_exists('cache_write', $rates) && ! is_numeric($rates['cache_write'])) {
            throw new \RuntimeException("{$label} の cache_write が数値ではありません");
        }

        return $rates;
    }
}
