<?php

namespace App\Actions;

use App\Models\AiUsage;
use App\Services\Ai\AiDecider;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ApiKeys;

/**
 * 保存済みの設定で判定エンジンに本当に繋がるかを、その場で確かめる（TestAiConnectionAction の判定版）。
 *
 * 極小の noul 質問を1回判定する（数トークン消費。purpose=connection_test で ai_usages に残る）。
 * 通信前に分かる未設定（実行先・キー・モデル）は先に切り分けて日本語で返し、
 * 実行時の失敗（認証エラー・モデル名違い等）は理由をそのまま文言に出す。
 */
class TestAiDecisionConnectionAction
{
    public function __construct(
        private readonly AiDecider $decider,
        private readonly AiSettings $settings,
        private readonly ApiKeys $keys,
    ) {}

    /**
     * @return array{ok: bool, message: string}
     */
    public function execute(): array
    {
        $blocked = $this->blockReason();

        if ($blocked !== null) {
            return ['ok' => false, 'message' => $blocked];
        }

        try {
            $usage = $this->decider->probe();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '接続に失敗しました: '.$e->getMessage()];
        }

        return ['ok' => true, 'message' => $this->successMessage($usage)];
    }

    /** 通信する前に分かる未設定を先に切り分ける */
    private function blockReason(): ?string
    {
        $provider = $this->settings->decisionProvider();

        if ($provider === null) {
            return '判定エンジンが未設定です。実行先を選んで保存してから試してください。';
        }

        if (! $this->keys->has($provider)) {
            return '.env の '.$provider->apiKeyEnvName().' が未設定です。設定してから試してください。';
        }

        if (! $this->settings->hasDecisionModel($provider)) {
            return '判定用モデルが未設定です。';
        }

        return null;
    }

    /** 今回の実行の記録から、実際に走ったモデルと消費トークンを添える（出力は無料なので入力だけ出す） */
    private function successMessage(?AiUsage $usage): string
    {
        if ($usage === null) {
            return '接続できました。ただし使用量の記録が残っていません（ログを確認してください）。';
        }

        $tokens = $usage->input_tokens === null ? 'トークン数は未取得' : "入力 {$usage->input_tokens} トークン";

        return "接続できました（モデル: {$usage->model}、消費: {$tokens}）。";
    }
}
