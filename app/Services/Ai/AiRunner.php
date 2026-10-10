<?php

namespace App\Services\Ai;

use App\Enums\AiProvider;
use App\Enums\AiPurpose;
use App\Enums\AiTier;
use App\Models\AiUsage;
use App\Services\Ai\Driver\AiRequest;
use App\Services\Ai\Driver\AiRequestFailed;
use App\Services\Ai\Driver\DriverRegistry;
use App\Services\Ai\Driver\TokenUsage;
use Illuminate\Support\Facades\Log;

/**
 * AI 実行の正規パス（1本）。呼び出し元は用途の重さ（AiTier）だけを渡し、実行先は設定から解決する。
 *
 * 手順: 空プロンプト拒否 → AiSettings::resolve → Driver::run → OutputValidator → 記録 → data。
 * 失敗時もサイレントにせず、記録してから例外を再送出する（サイレント失敗はしない）。
 * final にしない（テストが Mockery::mock(AiRunner::class) で差し替えるため）。
 */
class AiRunner
{
    /** 接続テストで使う極小スキーマ（数トークンで済ませる） */
    private const PROBE_SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['ok'],
        'properties' => ['ok' => ['type' => 'boolean']],
    ];

    private const PROBE_PROMPT = '接続テストです。{"ok": true} だけを返してください。';

    public function __construct(
        private readonly AiSettings $settings,
        private readonly DriverRegistry $drivers,
        private readonly OutputValidator $validator,
        private readonly AiPricing $pricing,
    ) {}

    /**
     * @param  array<string, mixed>  $schema  構造化出力スキーマ（root は object）
     * @param  AiTier  $tier  用途の重さ。実行先・モデルは ai_settings / ai_provider_configs から解決する
     * @param  AiPurpose|null  $purpose  何のための実行か（使用量画面の内訳に使う。null なら記録しない）
     * @param  string|null  $detail  用途の中の内訳（分析なら p1..p6）
     * @param  int|null  $companyId  どの企業のための実行か
     * @return array<string, mixed>
     */
    public function runWithSchema(
        array $schema,
        string $prompt,
        AiTier $tier,
        ?AiPurpose $purpose = null,
        ?string $detail = null,
        ?int $companyId = null,
    ): array {
        // 空のまま実行先に渡すと入力なしで走ってしまう。実行先を解決する前に落とす
        if (trim($prompt) === '') {
            throw new \InvalidArgumentException('AI prompt must not be empty');
        }

        return $this->execute($schema, $prompt, $this->settings->resolve($tier), $purpose, $detail, $companyId)['data'];
    }

    /**
     * 接続テスト。極小スキーマを1回実行し、purpose=ConnectionTest で記録する（経路は runWithSchema と同じ）。
     *
     * 戻り値は今回の実行の記録。記録できなかったときは null（過去の記録で代用しない）。
     */
    public function probe(AiProvider $provider): ?AiUsage
    {
        return $this->execute(self::PROBE_SCHEMA, self::PROBE_PROMPT, $this->settings->probeTarget($provider), AiPurpose::ConnectionTest)['usage'];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{data: array<string, mixed>, usage: AiUsage|null}
     */
    private function execute(
        array $schema,
        string $prompt,
        AiTarget $target,
        ?AiPurpose $purpose,
        ?string $detail = null,
        ?int $companyId = null,
    ): array {
        $startedAt = microtime(true);
        $request = new AiRequest($schema, $prompt, $target->model, $target->options, $target->timeout);
        $response = null;

        try {
            $response = $this->drivers->for($target->provider)->run($request);
            // どのドライバの応答も正規スキーマで検証してから PHP 後処理に渡す（方言差で欠けた項目を黙って通さない）
            $this->validator->assert($response->data, $schema);
            // 大きさの上限はスキーマの外側でアプリが検査する（注入で読み込ませた内容の丸写しを次段のプロンプトに流さない）
            $this->validator->assertBounded($response->data);
        } catch (\Throwable $e) {
            // 失敗も消費として記録する。課金済みの失敗（AiRequestFailed・検証で落ちた応答）は usage も残す
            $tokens = match (true) {
                $e instanceof AiRequestFailed => $e->usage,
                $response !== null => $response->tokens,
                default => null,
            };
            $this->record($purpose, $target, $detail, $companyId, $startedAt, $prompt, '', $tokens, $e);

            throw $e;
        }

        $usage = $this->record($purpose, $target, $detail, $companyId, $startedAt, $prompt, json_encode($response->data), $response->tokens, null);

        return ['data' => $response->data, 'usage' => $usage];
    }

    /**
     * 実行1回ぶんを記録する。計測が原因で分析を落とさないよう、
     * 記録できない場合もログに残して処理は続ける（記録は計測であって本処理ではない）。
     */
    private function record(
        ?AiPurpose $purpose,
        AiTarget $target,
        ?string $detail,
        ?int $companyId,
        float $startedAt,
        string $prompt,
        string|false $output,
        ?TokenUsage $tokens,
        ?\Throwable $error,
    ): ?AiUsage {
        if ($purpose === null) {
            return null;
        }

        try {
            return AiUsage::create([
                'provider' => $target->provider,
                'purpose' => $purpose,
                'model' => $target->model,
                'detail' => $detail,
                'company_id' => $companyId,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'prompt_chars' => mb_strlen($prompt),
                'output_chars' => $output === false ? 0 : mb_strlen($output),
                'input_tokens' => $tokens?->input,
                'cached_input_tokens' => $tokens?->cached,
                'cache_write_input_tokens' => $tokens?->cacheWrite,
                'output_tokens' => $tokens?->output,
                'reasoning_tokens' => $tokens?->reasoning,
                'cost_micro_usd' => $this->cost($target, $tokens),
                'usage_raw' => $tokens?->raw,
                'succeeded' => $error === null,
                'error' => $error?->getMessage(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AI の使用量を記録できませんでした: '.$e->getMessage());

            return null;
        }
    }

    /**
     * 推定費用は実行時点の単価表で確定する。単価未登録・トークン不明・codex は null（0 で埋めない）。
     *
     * 単価表の不備（AiPricing の例外）で実行の記録ごと失わないよう、ここだけ独立して受ける。
     * 費用が出せないだけの実行は cost=null として残り、使用状況画面が「単価未登録 N 件」で数える。
     */
    private function cost(AiTarget $target, ?TokenUsage $tokens): ?int
    {
        try {
            return $this->pricing->costMicroUsd($target->provider, $target->model, $tokens);
        } catch (\Throwable $e) {
            Log::warning('AI の推定費用を計算できませんでした（単価表を確認してください）: '.$e->getMessage());

            return null;
        }
    }
}
