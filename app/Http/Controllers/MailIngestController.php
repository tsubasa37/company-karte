<?php

namespace App\Http\Controllers;

use App\Actions\BuildMailPageAction;
use App\Actions\RetryMailIngestAction;
use App\Actions\RevertMailIngestAction;
use App\Models\MailIngest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MailIngestController extends Controller
{
    public function index(BuildMailPageAction $action): Response
    {
        return Inertia::render('Mails', $action->execute());
    }

    /** 目視したことの記録（承認ゲートではなく既読管理） */
    public function review(Request $request, MailIngest $ingest): RedirectResponse
    {
        $data = $request->validate(['reviewed' => ['required', 'boolean']]);

        $ingest->update(['reviewed' => $data['reviewed']]);

        return back();
    }

    /** 取り込みが作った企業・ソース・予定を取り消す（履歴は残す） */
    public function destroy(MailIngest $ingest, RevertMailIngestAction $action): RedirectResponse
    {
        $kept = $action->execute($ingest);

        // 消せなかったものがあれば画面に出す（一部だけ効いた取り消しを黙って返さない）
        return $kept === [] ? back() : back()->with('mailRevert', $kept);
    }

    /** 失敗した取り込み1件をやり直す（AI を1回使う） */
    public function retry(MailIngest $ingest, RetryMailIngestAction $action): RedirectResponse
    {
        abort_if($ingest->error === null, 404);

        $action->execute($ingest);

        return back();
    }

    /** 失敗した取り込みをまとめてやり直す（件数ぶん AI を使う。画面が件数を出してから押させる） */
    public function retryFailed(RetryMailIngestAction $action): RedirectResponse
    {
        MailIngest::whereNotNull('error')->get()->each(fn (MailIngest $ingest) => $action->execute($ingest));

        return back();
    }
}
