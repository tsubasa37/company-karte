<?php

namespace App\Services\Ai\Cost;

use App\Enums\AiProvider;

/**
 * 見積もりの対象にする実行先（プロバイダ + モデル名）。
 * モデル名は単価表（config/ai.php の pricing）・設定画面の入力と同じ文字列で引く。
 */
final readonly class SimulationTarget
{
    public function __construct(
        public AiProvider $provider,
        public string $model,
    ) {
        if (trim($model) === '') {
            throw new \InvalidArgumentException('見積もり対象のモデル名が空です');
        }

        if ($provider->isSubprocess()) {
            // codex はサブスクで走るため単価表を持たない。費用 0 の行として表に混ぜると
            // 「API より安い」と読めてしまうので、見積もりの対象にはしない
            throw new \InvalidArgumentException($provider->label().' はサブスク実行なので費用の見積もり対象になりません');
        }
    }
}
