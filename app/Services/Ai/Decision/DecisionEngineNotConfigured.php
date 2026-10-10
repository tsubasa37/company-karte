<?php

namespace App\Services\Ai\Decision;

/**
 * 判定エンジンが未設定（ai_settings.decision_provider が null）。機能がオフの状態で判定を呼んだ。
 */
class DecisionEngineNotConfigured extends \RuntimeException
{
    public const MESSAGE = '判定エンジンが未設定です。設定画面の「AIエンジン」→「判定エンジン」で実行先を選んで保存してください';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
