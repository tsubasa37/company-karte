<?php

namespace App\Http\Controllers;

use App\Actions\FetchMailAction;
use App\Models\GoogleCredential;
use App\Services\Mail\FromDomainAllowList;
use Illuminate\Http\RedirectResponse;

class MailFetchController extends Controller
{
    /**
     * 手動でいま取り込む（自動を選んでいなくても、押したときだけ取得する経路）。
     *
     * 押す前に分かる未設定は先に切り分けて画面へ返す。Gmail 側の失敗（認可切れ・権限不足）は
     * FetchMailAction の例外のまま外へ出す（握り潰さない）。
     */
    public function __invoke(FromDomainAllowList $allowList, FetchMailAction $action): RedirectResponse
    {
        $blocked = $this->blockReason($allowList);

        if ($blocked !== null) {
            return $this->to(['ok' => false, 'message' => $blocked]);
        }

        $result = $action->execute();

        // 上限で積まなかった分は黙って捨てず、もう一度押せば進むことまで出す
        $pending = $result['pending'] > 0
            ? "1回の上限を超えたため {$result['pending']}件は積んでいません（もう一度押すと続きから進みます）。"
            : '';

        return $this->to([
            'ok' => true,
            'message' => "該当 {$result['found']}件 / 新規 {$result['queued']}件を取り込みキューに積みました。"
                .$pending.'取り込みの結果は「取り込み履歴を見る」で確認できます。',
        ]);
    }

    /** 通信する前に分かる未設定 */
    private function blockReason(FromDomainAllowList $allowList): ?string
    {
        if (GoogleCredential::query()->doesntExist()) {
            return 'まだ連携していません。「Gmailと連携する」から Google にログインしてください。';
        }

        if (! $allowList->isConfigured()) {
            return '取り込む差出人ドメインが未設定です。このままでは1通も取り込みません。';
        }

        return null;
    }

    /** @param  array{ok: bool, message: string}  $result */
    private function to(array $result): RedirectResponse
    {
        // 結果は設定画面にだけ出すため戻り先を明示する（back() は Referer 頼みで、無い環境では別の画面へ戻る）
        return redirect()->route('settings.mail')->with('mailFetch', $result);
    }
}
