<?php

namespace App\Console\Commands;

use App\Actions\FetchMailAction;
use Illuminate\Console\Command;

/**
 * Gmail から転職関連メールを取り込む。スケジューラから15分おきに実行される。
 * 手動確認用に `php artisan mail:fetch` としても実行できる。
 */
class FetchMailCommand extends Command
{
    protected $signature = 'mail:fetch';

    protected $description = '許可した差出人ドメインの転職関連メールを Gmail から取り込む';

    public function handle(FetchMailAction $action): int
    {
        $result = $action->execute();

        $this->info("該当 {$result['found']}件 / 新規 {$result['queued']}件を取り込みキューに積みました");

        if ($result['pending'] > 0) {
            $this->warn("1回の上限を超えたため {$result['pending']}件は積んでいません（次回の取得で続きから進みます）");
        }

        return self::SUCCESS;
    }
}
