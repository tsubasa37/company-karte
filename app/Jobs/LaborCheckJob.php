<?php

namespace App\Jobs;

use App\Jobs\Concerns\AssertsQueueHeadroom;
use App\Services\LaborViolationChecker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 労基公表リスト突合を非同期で実行する（PDF取得・解析に数十秒かかるため）。
 * 失敗は failed_jobs に残る（画面上は labor_checked_at が更新されないことで分かる）。
 */
class LaborCheckJob implements ShouldQueue
{
    use AssertsQueueHeadroom;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct()
    {
        $this->assertQueueHeadroom();
    }

    public function handle(LaborViolationChecker $checker): void
    {
        $checker->checkAll();
    }
}
