<?php

namespace App\Actions;

use App\Jobs\IngestMailJob;
use App\Models\GoogleCredential;
use App\Models\MailIngest;
use App\Services\Google\GmailClient;
use App\Services\Google\GoogleOAuthClient;
use App\Services\Mail\FromDomainAllowList;

/**
 * Gmail から該当メールのIDだけを引き、未取り込みのものを取り込みジョブに積む。
 *
 * 一覧は窓の中を遡って引き（scan_messages）、AI を使う取り込みだけを上限で抑える（max_messages）。
 * 一覧まで上限で切ると、上限を超えて溜まったメールへ永久に辿り着けない（毎回同じ先頭が返る）。
 * 上限で積まなかった分は次回の取得で進むため、その件数を戻り値に出して画面に伝える。
 *
 * 本文の取得と codex 抽出はジョブ側で行う（1通ずつ失敗を切り分けられるようにするため）。
 * 連携未設定・許可ドメイン未設定は黙って0件にせず例外にする。
 * 取得の失敗は google_credentials.last_fetch_error に残す（スケジューラの失敗はログにしか出ないため）。
 */
class FetchMailAction
{
    /** 失敗理由として残す長さの上限 */
    private const MAX_ERROR_LENGTH = 2000;

    public function __construct(
        private readonly GoogleOAuthClient $oauth,
        private readonly GmailClient $gmail,
        private readonly FromDomainAllowList $allowList,
    ) {}

    /**
     * @return array{found: int, queued: int, pending: int}
     */
    public function execute(): array
    {
        if (! $this->allowList->isConfigured()) {
            throw new \RuntimeException(
                '取り込む差出人ドメインが未設定です。設定画面のGmail連携から登録してください。'
            );
        }

        $credential = GoogleCredential::first();

        if ($credential === null) {
            throw new \RuntimeException('Gmail と連携されていません。設定画面から「Gmailと連携する」を実行してください。');
        }

        try {
            $ids = $this->gmail->listMessageIds(
                $this->oauth->accessToken($credential),
                $this->query(),
                (int) config('services.mail_ingest.scan_messages'),
            );

            $known = MailIngest::whereIn('external_id', $ids)->pluck('external_id')->all();
            $fresh = array_values(array_diff($ids, $known));

            // AI 実行数の歯止めは「積む件数」に掛ける。新しいものから積み、残りは次回に回す
            $queue = array_slice($fresh, 0, (int) config('services.mail_ingest.max_messages'));

            foreach ($queue as $id) {
                IngestMailJob::dispatch($id);
            }
        } catch (\Throwable $e) {
            // 失敗を画面に出せるよう残してから再送出する（握り潰さない）
            $credential->update([
                'last_fetch_error' => mb_substr($e->getMessage(), 0, self::MAX_ERROR_LENGTH),
                'last_fetch_error_at' => now(),
            ]);

            throw $e;
        }

        $credential->update([
            'last_fetched_at' => now(),
            'last_fetch_error' => null,
            'last_fetch_error_at' => null,
        ]);

        return [
            'found' => count($ids),
            'queued' => count($queue),
            'pending' => count($fresh) - count($queue),
        ];
    }

    /**
     * サーバー側で絞る検索クエリ。該当しないメールはそもそも手元に落ちてこない。
     * newer_than の窓により、Mac を閉じていた間の分も次回実行でまとめて拾える。
     */
    private function query(): string
    {
        $days = (int) config('services.mail_ingest.window_days');

        return $this->allowList->gmailQuery()." newer_than:{$days}d";
    }
}
