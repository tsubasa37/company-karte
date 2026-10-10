<?php

namespace App\Http\Controllers;

use App\Actions\ScreenNewsAction;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;

class NewsScreenController extends Controller
{
    /** 未判定のニュースを機械判定し、結果をニュース画面に返す（戻り先を明示する理由は AiRoutingController と同じ） */
    public function __invoke(Company $company, ScreenNewsAction $action): RedirectResponse
    {
        return redirect()->route('companies.news', $company)->with('newsScreen', $action->execute($company));
    }
}
