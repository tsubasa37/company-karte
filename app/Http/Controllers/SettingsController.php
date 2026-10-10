<?php

namespace App\Http\Controllers;

use App\Actions\BuildAiStatusAction;
use App\Actions\BuildAiUsageAction;
use App\Actions\BuildBackupStatusAction;
use App\Actions\BuildGmailStatusAction;
use App\Models\Profile;
use App\Services\Mail\MailAutoFetch;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 設定は1節=1ページ。各メソッドはその節の描画に要る props だけを渡す。
 *
 * 全節を1ページに載せていた頃は、プロファイルの誤字を直しに来ただけでも
 * AI 使用状況の集計（SQL 9 本 + セッションログ読み・実測 113ms）が走っていた。
 */
class SettingsController extends Controller
{
    /** 設定の入口。節を持たないので既定の節へ送る */
    public function index(): RedirectResponse
    {
        return redirect()->route('settings.profile');
    }

    public function profile(): Response
    {
        // プロファイルは1件だけ持つ（自分専用アプリのため）
        return Inertia::render('Settings/Profile', ['profile' => Profile::first()]);
    }

    public function ai(BuildAiStatusAction $aiStatus, MailAutoFetch $autoFetch): Response
    {
        // 実行先・モデルの設定。API キーは含まれない（登録済みかどうかだけ）
        return Inertia::render('Settings/Ai', [
            'ai' => $aiStatus->execute(),
            // 「押していなくても走る」の説明に使う。実際に自動取得が走る状態のときだけ true
            'mailAutoFetch' => $autoFetch->isActive(),
        ]);
    }

    public function usage(BuildAiUsageAction $aiUsage): Response
    {
        // 集計 SQL と codex セッションログの末尾読みを伴う。このページでだけ払う
        return Inertia::render('Settings/Usage', ['aiUsage' => $aiUsage->execute()]);
    }

    public function mail(BuildGmailStatusAction $gmailStatus): Response
    {
        return Inertia::render('Settings/Mail', ['gmail' => $gmailStatus->execute()]);
    }

    public function backup(BuildBackupStatusAction $backupStatus): Response
    {
        return Inertia::render('Settings/Backup', ['backup' => $backupStatus->execute()]);
    }
}
