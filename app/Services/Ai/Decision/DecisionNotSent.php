<?php

namespace App\Services\Ai\Decision;

/**
 * 一括判定で、先に送った区切りが失敗したため送らなかったリクエスト。通信していないので使用量には記録しない。
 */
class DecisionNotSent extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('先に送った判定が失敗したため送っていません');
    }
}
