<?php

namespace App\Http\Controllers;

use App\Actions\SaveMailSettingAction;
use App\Http\Requests\MailSettingRequest;
use Illuminate\Http\RedirectResponse;

class MailSettingController extends Controller
{
    public function update(MailSettingRequest $request, SaveMailSettingAction $action): RedirectResponse
    {
        $action->execute($request->validated());

        // 結果は設定画面にだけ出すため戻り先を明示する（back() は Referer 頼みで、無い環境では別の画面へ戻る）
        return redirect()->route('settings.mail');
    }
}
