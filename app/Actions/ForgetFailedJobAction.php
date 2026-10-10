<?php

namespace App\Actions;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * failed_jobs の1件を削除する（ダッシュボードの⛔一覧から取り除く）。
 * 直接 DELETE せず artisan queue:forget を経由し、Laravel 標準の
 * failed プロバイダ（database-uuids）の削除経路に乗せる。
 */
class ForgetFailedJobAction
{
    public function execute(int $id): void
    {
        // database-uuids ドライバのため queue:forget には UUID を渡す
        $uuid = DB::table('failed_jobs')->where('id', $id)->value('uuid');
        abort_if($uuid === null, 404);

        if (Artisan::call('queue:forget', ['id' => $uuid]) !== 0) {
            throw new RuntimeException("failed_jobs #{$id} の削除に失敗しました（queue:forget が異常終了）");
        }
    }
}
