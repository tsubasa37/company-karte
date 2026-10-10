<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;

class BackupController extends Controller
{
    /** 「今すぐバックアップ」。失敗はサイレントにせず例外で表面化させる */
    public function store(): RedirectResponse
    {
        $exitCode = Artisan::call('karte:backup');

        if ($exitCode !== 0) {
            throw new \RuntimeException("karte:backup が失敗しました（exit code: {$exitCode}）");
        }

        return back();
    }
}
