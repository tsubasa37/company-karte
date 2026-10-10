<?php

namespace App\Http\Controllers;

use App\Actions\TestGmailConnectionAction;
use Illuminate\Http\RedirectResponse;

class GmailConnectionTestController extends Controller
{
    /** 保存済みの設定で疎通を確認し、結果を画面に返す */
    public function __invoke(TestGmailConnectionAction $action): RedirectResponse
    {
        $result = $action->execute();

        // 結果は設定画面にだけ出すため戻り先を明示する（back() は Referer 頼みで、無い環境では別の画面へ戻る）
        return redirect()->route('settings.mail')->with('gmailTest', $result);
    }
}
