<?php

use App\Services\Mail\MailAutoFetch;
use Illuminate\Support\Facades\Schedule;

// Gmail からの転職メール取り込み。1日2回では日程調整に遅く、5分おきは codex を叩きすぎるため15分。
// 実行には schedule:work が必要（bin/dev.sh が起動する）。閉じていた間の分は
// 取得クエリの newer_than 窓で次回にまとめて拾う。
// 設定画面で「自動」を選んでいるときだけ走る。既定は手動（ボタン）で、
// 選んでいない間は Gmail にも AI にも一切アクセスしない。
Schedule::command('mail:fetch')
    ->everyFifteenMinutes()
    // 前回の取得が長引いているときに重ねて走らせない
    ->withoutOverlapping()
    ->when(fn () => app(MailAutoFetch::class)->isEnabled());
