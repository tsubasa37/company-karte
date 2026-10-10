<?php

namespace App\Services\Ai\Decision;

use App\Services\Ai\AiTarget;

/**
 * 判定エンジンの実行先の契約。AiDecider はこの契約だけを見て実行する。
 */
interface DecisionDriver
{
    /**
     * 失敗は例外にする（null や空の回答で誤魔化さない）。
     * 応答は返ったが形が壊れている等の課金済みの失敗は AiRequestFailed に usage を載せて投げる。
     */
    public function decide(DecisionRequest $request, AiTarget $target): DecisionResponse;

    /**
     * 複数の判定をまとめて送る。戻り値は $requests と同じ添字で、失敗した要素は例外オブジェクト（ここでは投げない）。
     * 実行先の設定の不備（モデル名・接続先・キー）は送る前に例外にする。
     *
     * @param  list<DecisionRequest>  $requests
     * @return list<DecisionResponse|\Throwable>
     */
    public function decideMany(array $requests, AiTarget $target): array;
}
