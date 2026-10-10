<?php

namespace App\Services;

use App\Models\SourceDocument;

/**
 * 同一URL・同一種別のソースを上書きする際、本文が変わる場合のみ旧本文を履歴に退避する。
 * 求人票の文言変化（給与レンジ変更・「急募」追加等）を後から差分で確認するため。
 */
class SourceRevisionRecorder
{
    public function record(?SourceDocument $existing, string $newText): void
    {
        if ($existing === null || (string) $existing->raw_text === $newText) {
            return;
        }

        $existing->revisions()->create([
            'raw_text' => (string) $existing->raw_text,
            'replaced_at' => now(),
        ]);
    }
}
