<?php

namespace App\Http\Controllers;

use App\Actions\FetchGbizinfoAction;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;

class GbizinfoController extends Controller
{
    public function store(Company $company, FetchGbizinfoAction $action): RedirectResponse
    {
        // 取得失敗はエラーとして画面に明示する（握り潰さない）
        try {
            $action->execute($company);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return back()->withErrors(['gbizinfo' => $e->getMessage()]);
        }

        return back();
    }
}
