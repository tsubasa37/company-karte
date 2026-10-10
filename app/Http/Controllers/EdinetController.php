<?php

namespace App\Http\Controllers;

use App\Jobs\FetchEdinetJob;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;

class EdinetController extends Controller
{
    public function store(Company $company): RedirectResponse
    {
        // 取得中の再要求は弾く（初回は400日分の走査が走るため二重実行の負荷が大きい）
        if ($company->edinet_requested_at !== null) {
            return back()->withErrors(['edinet' => 'EDINETから取得中です。完了までお待ちください。']);
        }

        if (! $company->listed) {
            return back()->withErrors(['edinet' => 'EDINET（有報）は上場企業のみ取得できます。基本情報タブで上場フラグを確認してください。']);
        }
        if (blank($company->legal_number)) {
            return back()->withErrors(['edinet' => '法人番号が未設定です。基本情報タブで法人番号を入力してください。']);
        }
        if (blank(config('services.edinet.key'))) {
            return back()->withErrors(['edinet' => 'EDINET_API_KEY が未設定です（https://api.edinet-fsa.go.jp/ で無料発行し .env に設定してください）。']);
        }

        $company->update(['edinet_requested_at' => now(), 'edinet_error' => null]);
        FetchEdinetJob::dispatch($company->id);

        return back();
    }
}
