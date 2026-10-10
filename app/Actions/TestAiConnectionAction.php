<?php

namespace App\Actions;

use App\Enums\AiProvider;
use App\Models\AiProviderConfig;
use App\Models\AiUsage;
use App\Services\Ai\AiRunner;
use App\Services\Ai\ApiKeys;

/**
 * 保存済みの設定で本当にその実行先に繋がるかを、その場で確かめる。
 *
 * 極小スキーマを1回実行する（数トークン消費。purpose=connection_test で ai_usages に残る）。
 * 通信前に分かる未設定（キー・モデル）は先に切り分けて日本語で返し、
 * 実行時の失敗（認証エラー・モデル名違い等）は理由をそのまま文言に出す。
 */
class TestAiConnectionAction
{
    public function __construct(
        private readonly AiRunner $runner,
        private readonly ApiKeys $keys,
    ) {}

    /**
     * @return array{ok: bool, message: string}
     */
    public function execute(AiProvider $provider): array
    {
        $blocked = $this->blockReason($provider);

        if ($blocked !== null) {
            return ['ok' => false, 'message' => $blocked];
        }

        try {
            $usage = $this->runner->probe($provider);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '接続に失敗しました: '.$e->getMessage()];
        }

        return ['ok' => true, 'message' => $this->successMessage($usage)];
    }

    /** 通信する前に分かる未設定を先に切り分ける（codex はキーもモデル必須も無いので対象外） */
    private function blockReason(AiProvider $provider): ?string
    {
        if ($provider->isSubprocess()) {
            return null;
        }

        if (! $this->keys->has($provider)) {
            return '.env の '.$provider->apiKeyEnvName().' が未設定です。設定してから試してください。';
        }

        $config = AiProviderConfig::where('provider', $provider->value)->first();

        if (blank($config?->model_analysis) && blank($config?->model_light)) {
            return $provider->label().' のモデルが未設定です。分析・要約か抽出・判定のどちらかにモデル名を入れてください。';
        }

        return null;
    }

    /** 今回の実行の記録から、実際に走ったモデルと消費トークンを添える（過去の記録では代用しない） */
    private function successMessage(?AiUsage $usage): string
    {
        if ($usage === null) {
            return '接続できました。ただし使用量の記録が残っていません（ログを確認してください）。';
        }

        $model = filled($usage->model) ? $usage->model : '既定モデル';
        $tokens = $usage->input_tokens === null && $usage->output_tokens === null
            ? 'トークン数は未取得'
            : '入力 '.($usage->input_tokens ?? '?').' / 出力 '.($usage->output_tokens ?? '?').' トークン';

        return "接続できました（モデル: {$model}、消費: {$tokens}）。";
    }
}
