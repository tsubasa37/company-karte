<?php

namespace App\Services\Ai\Decision;

/**
 * 質問の共通の入力検査。送ってから API 側のエラーで知るのではなく、組み立てた時点で落とす。
 */
final class QuestionGuard
{
    public static function assertBasics(string $id, string $instructions): void
    {
        if (trim($id) === '') {
            throw new \InvalidArgumentException('判定の質問 id が空です');
        }

        // 数字だけの id は PHP の配列で整数の鍵になり、0 からの連番だと questions が JSON の配列に化ける
        if (ctype_digit($id)) {
            throw new \InvalidArgumentException("判定の質問 id に数字だけの値は使えません（{$id}）");
        }

        if (trim($instructions) === '') {
            throw new \InvalidArgumentException("判定の質問 {$id} の instructions が空です");
        }
    }
}
