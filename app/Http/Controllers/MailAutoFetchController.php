<?php

namespace App\Http\Controllers;

use App\Models\MailSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MailAutoFetchController extends Controller
{
    /**
     * 取り込み方法（自動 / 手動）の切り替え。
     * 自動にした瞬間から、次のスケジューラ実行（15分おき）で取得が始まる。
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['auto_fetch' => ['required', 'boolean']]);

        $setting = MailSetting::query()->firstOrNew();
        $setting->auto_fetch = $data['auto_fetch'];
        $setting->save();

        // 結果は設定画面にだけ出すため戻り先を明示する（back() は Referer 頼みで、無い環境では別の画面へ戻る）
        return redirect()->route('settings.mail');
    }
}
