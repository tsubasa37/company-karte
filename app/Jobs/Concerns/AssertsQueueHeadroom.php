<?php

namespace App\Jobs\Concerns;

use App\Services\QueueHeadroom;

/**
 * 投入時に、このジョブの締め切り（timeout）がキューの再予約時間に収まることを確かめる。
 * 収まらないと同じジョブが二重に走って AI を二重に消費するため、黙って積まず例外で止める。
 * ワーカー側の unserialize ではコンストラクタが走らないので、検査は投入時の1回だけ。
 */
trait AssertsQueueHeadroom
{
    protected function assertQueueHeadroom(): void
    {
        app(QueueHeadroom::class)->assertFits($this->timeout, class_basename(static::class));
    }
}
