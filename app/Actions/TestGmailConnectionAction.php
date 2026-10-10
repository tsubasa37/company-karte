<?php

namespace App\Actions;

use App\Models\GoogleCredential;
use App\Services\Google\GmailClient;
use App\Services\Google\GoogleOAuthClient;
use App\Services\Mail\FromDomainAllowList;

/**
 * 保存済みの設定で本当に Gmail に繋がるかを、その場で確かめる。
 *
 * 設定を保存できたことと、実際に取得できることは別。認可が切れている・スコープが足りない・
 * ドメイン指定が誤っている、といった失敗は取り込みが走るまで気づけないため、
 * 押した瞬間に切り分けて日本語で返す。
 *
 * 取得するのは件数だけで、本文には触れない（確認のために codex を使わない）。
 */
class TestGmailConnectionAction
{
    public function __construct(
        private readonly GoogleOAuthClient $oauth,
        private readonly GmailClient $gmail,
        private readonly FromDomainAllowList $allowList,
    ) {}

    /**
     * @return array{ok: bool, message: string}
     */
    public function execute(): array
    {
        $blocked = $this->blockReason();

        if ($blocked !== null) {
            return ['ok' => false, 'message' => $blocked];
        }

        $days = (int) config('services.mail_ingest.window_days');

        // 失敗の理由（認可切れ・権限不足等）を握り潰さず、そのまま文言に出す
        try {
            $ids = $this->gmail->listMessageIds(
                $this->oauth->accessToken(GoogleCredential::query()->first()),
                $this->allowList->gmailQuery()." newer_than:{$days}d",
                (int) config('services.mail_ingest.max_messages'),
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '接続に失敗しました: '.$e->getMessage()];
        }

        if ($ids === []) {
            return [
                'ok' => true,
                'message' => "接続できました。ただし直近{$days}日に該当するメールは0件です"
                    .'（差出人ドメインの指定を確認してください）。',
            ];
        }

        return ['ok' => true, 'message' => '接続できました。直近'.$days.'日に該当するメールが'.count($ids).'件あります。'];
    }

    /** 通信する前に分かる未設定を先に切り分ける */
    private function blockReason(): ?string
    {
        if (! $this->oauth->isConfigured()) {
            return 'クライアントIDとシークレットが未登録です。下のフォームから登録してください。';
        }

        if (GoogleCredential::query()->doesntExist()) {
            return 'まだ連携していません。「Gmailと連携する」から Google にログインしてください。';
        }

        if (! $this->allowList->isConfigured()) {
            return '取り込む差出人ドメインが未設定です。このままでは1通も取り込みません。';
        }

        return null;
    }
}
