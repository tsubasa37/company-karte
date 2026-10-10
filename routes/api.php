<?php

use App\Http\Controllers\ClipController;
use Illuminate\Support\Facades\Route;

// ブラウザ拡張からのクリップ投入（共有トークンで保護・セッション/CSRF なし）
// throttle は暴走・総当たり対策の歯止め（1クリップ=codex実行のため）。
Route::post('/clip', [ClipController::class, 'store'])->middleware(['clip.token', 'throttle:30,1']);

// 「お気に入り・いいね一覧」ページからの企業一括登録（同じ保護）
Route::post('/clip-list', [ClipController::class, 'storeList'])->middleware(['clip.token', 'throttle:30,1']);

// 拡張の「投入先企業」選択UI用の一覧（同じ保護）。codex を使わない参照のみなので上限は緩め
Route::get('/companies', [ClipController::class, 'companies'])->middleware(['clip.token', 'throttle:120,1']);
