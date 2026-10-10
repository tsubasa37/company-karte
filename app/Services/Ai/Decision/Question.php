<?php

namespace App\Services\Ai\Decision;

/**
 * 判定エンジンに渡す型付きの質問 1 件。id は応答の answers の鍵になる。
 */
interface Question
{
    public function id(): string;

    /**
     * 送信する形（questions.{id} の値）。
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array;
}
