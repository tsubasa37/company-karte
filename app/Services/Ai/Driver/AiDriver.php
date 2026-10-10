<?php

namespace App\Services\Ai\Driver;

/**
 * AI の実行先（codex / 各社 API）の契約。AiRunner はこの契約だけを見て実行する。
 */
interface AiDriver
{
    /**
     * 失敗は例外にする（null や空配列で誤魔化さない）。
     * 出力上限での打ち切り・refusal など課金済みの失敗は AiRequestFailed に usage を載せて投げる。
     */
    public function run(AiRequest $request): AiResponse;
}
