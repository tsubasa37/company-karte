<?php

namespace App\Actions;

use Illuminate\Support\Facades\Artisan;

/**
 * 分析開始前の必須バックアップ。世代の置き換え（既定1件）は karte:backup 側が持つ。
 *
 * 分析は company.recommendation を上書きする破壊的操作で、復元点が無い状態で
 * 走らせるとやり直せなくなる。新規実行（RunAnalysisAction）と失敗ランの再開
 * （AnalysisController::retry）の両方がここを通る（再開だけ素通りさせない）。
 * 失敗しても分析を続ける（握り潰す）ことはせず、例外にして画面に出す。
 */
class BackupBeforeAnalysisAction
{
    public function execute(): void
    {
        $exitCode = Artisan::call('karte:backup');

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                "分析前のバックアップ（karte:backup）が失敗したため分析を開始しません（exit code: {$exitCode}）"
            );
        }
    }
}
