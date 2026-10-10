<?php

use App\Http\Controllers\AiConnectionTestController;
use App\Http\Controllers\AiDecisionController;
use App\Http\Controllers\AiDecisionTestController;
use App\Http\Controllers\AiProviderConfigController;
use App\Http\Controllers\AiRoutingController;
use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BriefingController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompareController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EdinetController;
use App\Http\Controllers\FailedJobController;
use App\Http\Controllers\GbizinfoController;
use App\Http\Controllers\GmailConnectionTestController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\InterviewNoteController;
use App\Http\Controllers\KarteExportController;
use App\Http\Controllers\LaborCheckController;
use App\Http\Controllers\MailAutoFetchController;
use App\Http\Controllers\MailFetchController;
use App\Http\Controllers\MailIngestController;
use App\Http\Controllers\MailSettingController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\NewsScreenController;
use App\Http\Controllers\OfficialMetricController;
use App\Http\Controllers\OpenIssueController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\ScheduleEventController;
use App\Http\Controllers\ScheduleIcsController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SourceDocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::post('/labor-check', [LaborCheckController::class, 'store'])->name('labor.check');
// ダッシュボード: 失敗ジョブ（failed_jobs）の削除
Route::delete('/failed-jobs/{id}', [FailedJobController::class, 'destroy'])->name('failed-jobs.destroy');

// Gmail連携の設定（OAuthクライアント・取り込み条件）。設定ファイルではなく画面から入れる
Route::put('/settings/mail', [MailSettingController::class, 'update'])->name('mail-settings.update');
// 保存した設定で実際に繋がるかをその場で確かめる（取り込みが走るまで失敗に気づけないのを防ぐ）
Route::post('/settings/mail/test', GmailConnectionTestController::class)->name('mail-settings.test');
// 取り込み方法（自動=15分おき / 手動=ボタン）。既定は手動
Route::put('/settings/mail/auto-fetch', [MailAutoFetchController::class, 'update'])->name('mail-settings.auto-fetch');
// 手動で「いま取り込む」。自動を選んでいなくてもこの経路だけは押したときに走る
Route::post('/settings/mail/fetch', MailFetchController::class)->name('mail-settings.fetch');

// AI エンジン: 用途の重さ（Tier）ごとの実行先（ai_settings）。候補は codex + API キー登録済みのプロバイダ
Route::put('/settings/ai/routing', [AiRoutingController::class, 'update'])->name('ai-routing.update');
// プロバイダごとのモデル・固有オプション（ai_provider_configs）。{provider} は AiProvider の値。
// API キーはこの経路で受け取らない（置き場所は .env のみ。送られてきたら FormRequest が拒否する）
Route::put('/settings/ai/providers/{provider}', [AiProviderConfigController::class, 'update'])->name('ai-provider.update');
// 保存した設定で実際に繋がるかを極小スキーマ1回で確かめる（数トークン消費。purpose=connection_test で記録される）
Route::post('/settings/ai/providers/{provider}/test', AiConnectionTestController::class)->name('ai-provider.test');
// 判定エンジン（判定専用の実行先と判定用モデル。ai_settings.decision_provider）。空で保存すると機能オフ
Route::put('/settings/ai/decision', [AiDecisionController::class, 'update'])->name('ai-decision.update');
// 保存した判定エンジンに繋がるかを noul 1問で確かめる（数トークン消費。purpose=connection_test で記録される）
Route::post('/settings/ai/decision/test', AiDecisionTestController::class)->name('ai-decision.test');

// Gmail 取得のための一度きりの認可（画面ログイン機能ではない）
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');
Route::delete('/auth/google', [GoogleAuthController::class, 'destroy'])->name('google.destroy');

// メール取り込みの履歴（何を自動登録したかの追跡と取り消し）
Route::get('/mails', [MailIngestController::class, 'index'])->name('mails.index');
Route::patch('/mails/{ingest}/reviewed', [MailIngestController::class, 'review'])->name('mails.review');
Route::delete('/mails/{ingest}', [MailIngestController::class, 'destroy'])->name('mails.destroy');
// 取り込み失敗のやり直し（記録を消して同じメールを再度キューへ。AI を使うので押したときだけ）
Route::post('/mails/retry-failed', [MailIngestController::class, 'retryFailed'])->name('mails.retry-failed');
Route::post('/mails/{ingest}/retry', [MailIngestController::class, 'retry'])->name('mails.retry');

// 設定は1節=1ページ。サイドの項目とこの並びが1対1で対応する
Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
Route::get('/settings/profile', [SettingsController::class, 'profile'])->name('settings.profile');
Route::get('/settings/ai', [SettingsController::class, 'ai'])->name('settings.ai');
Route::get('/settings/usage', [SettingsController::class, 'usage'])->name('settings.usage');
Route::get('/settings/mail', [SettingsController::class, 'mail'])->name('settings.mail');
Route::get('/settings/backup', [SettingsController::class, 'backup'])->name('settings.backup');

// 各節の更新・実行。表示（上）と同じ URL に別のメソッドで載せる
Route::put('/settings/profile', [ProfileController::class, 'update'])->name('settings.profile.update');
Route::post('/settings/backup', [BackupController::class, 'store'])->name('settings.backup.store');

// 使い方。画面内ヘルプ（ⓘ）に収まらない流れ・分析結果の読み方・用語をまとめた静的なページ
Route::get('/help', HelpController::class)->name('help');

// 選考の予定表（面談・面接）。企業ページからも同じルートに投げる
Route::get('/schedule', [ScheduleEventController::class, 'index'])->name('schedule.index');
Route::post('/schedule', [ScheduleEventController::class, 'store'])->name('schedule.store');
Route::put('/schedule/{event}', [ScheduleEventController::class, 'update'])->name('schedule.update');
Route::delete('/schedule/{event}', [ScheduleEventController::class, 'destroy'])->name('schedule.destroy');
// カレンダーへの取り込みは「押したときだけ」。外部カレンダーとの自動同期はしない
Route::get('/schedule/{event}/ics', ScheduleIcsController::class)->name('schedule.ics');

// 取り込んだ材料の横断検索（企業をまたいで「残業」等を引く）
Route::get('/search', [SearchController::class, 'index'])->name('search');

Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
Route::get('/compare', [CompareController::class, 'index'])->name('compare');
Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
// 一覧のチェックボックスからの一括削除（1社ずつの削除は companies.destroy）
Route::delete('/companies', [CompanyController::class, 'destroyBulk'])->name('companies.destroy-bulk');
Route::get('/companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
Route::put('/companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
// 一覧の★トグル（値指定で冪等。並び替えの第一キーに使う）
Route::patch('/companies/{company}/favorite', [CompanyController::class, 'updateFavorite'])->name('companies.favorite');
Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])->name('companies.destroy');

Route::get('/companies/{company}/news', [NewsController::class, 'index'])->name('companies.news');
// 未判定のニュースをボタンで一括機械判定する（見るたびには判定しない）
Route::post('/companies/{company}/news/screen', NewsScreenController::class)->name('companies.news.screen');
// カルテの Markdown エクスポート（vault 等への持ち出し用）
Route::get('/companies/{company}/export.md', KarteExportController::class)->name('companies.export');

Route::post('/companies/{company}/sources', [SourceDocumentController::class, 'store'])->name('sources.store');
Route::post('/companies/{company}/interviews', [InterviewNoteController::class, 'store'])->name('interviews.store');
Route::get('/sources/{source}', [SourceDocumentController::class, 'show'])->name('sources.show');
// 種別・企業の付け替えと面接メモ本文の編集（派生データの作り直しを伴う）
Route::patch('/sources/{source}', [SourceDocumentController::class, 'update'])->name('sources.update');
// 同じURLから取り直して書き換えに気づく（URLの無いソースは 404）
Route::post('/sources/{source}/refetch', [SourceDocumentController::class, 'refetch'])->name('sources.refetch');
Route::delete('/sources/{source}', [SourceDocumentController::class, 'destroy'])->name('sources.destroy');

Route::post('/companies/{company}/metrics', [OfficialMetricController::class, 'store'])->name('metrics.store');
Route::delete('/metrics/{metric}', [OfficialMetricController::class, 'destroy'])->name('metrics.destroy');
Route::post('/companies/{company}/gbizinfo', [GbizinfoController::class, 'store'])->name('gbizinfo.store');
Route::post('/companies/{company}/edinet', [EdinetController::class, 'store'])->name('edinet.store');

// 面接で確認する論点の追跡。本文は画面から受け取らず、指定ランの P2 出力から読む
Route::post('/companies/{company}/issues', [OpenIssueController::class, 'store'])->name('issues.store');
Route::patch('/issues/{issue}', [OpenIssueController::class, 'update'])->name('issues.update');
Route::delete('/issues/{issue}', [OpenIssueController::class, 'destroy'])->name('issues.destroy');

Route::post('/companies/{company}/briefing', [BriefingController::class, 'store'])->name('briefing.store');

// 逆質問は分析(P1〜P5)から外し、面接が決まってから個別に生成する（無駄な codex 消費を避ける）
Route::post('/companies/{company}/questions', [QuestionController::class, 'store'])
    ->name('questions.store');

Route::post('/companies/{company}/analyze', [AnalysisController::class, 'store'])->name('analyze.store');
Route::post('/companies/{company}/runs/{run}/retry', [AnalysisController::class, 'retry'])->name('analyze.retry');
// 応答が途絶えたラン（ワーカーの強制終了など）を失敗扱いにして、再開・再分析・削除を可能にする
Route::post('/companies/{company}/runs/{run}/abort', [AnalysisController::class, 'abort'])->name('analyze.abort');
