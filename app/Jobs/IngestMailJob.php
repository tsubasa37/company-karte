<?php

namespace App\Jobs;

use App\Actions\IngestMailAction;
use App\Jobs\Concerns\AssertsQueueHeadroom;
use App\Models\GoogleCredential;
use App\Services\Google\GmailClient;
use App\Services\Google\GoogleOAuthClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * メール1通ぶんの取り込み（本文取得 → codex 抽出 → 企業・ソース・予定の登録）。
 *
 * 1通1ジョブにして、1通の失敗が他の取り込みを巻き込まないようにする。
 * 失敗は failed_jobs に残り、ダッシュボードの失敗ジョブ欄に出る（握り潰さない）。
 * AI 抽出を通った後の失敗は IngestMailAction が mail_ingests に「取り込み失敗」として残し、
 * 次回の取得で同じメールに AI を使い直さない。
 */
class IngestMailJob implements ShouldQueue
{
    use AssertsQueueHeadroom;
    use Queueable;

    public int $tries = 1;

    /** codex の実行を含むため、CODEX_TIMEOUT より余裕を持たせる */
    public int $timeout = 300;

    public function __construct(public string $messageId)
    {
        $this->assertQueueHeadroom();
    }

    public function handle(GoogleOAuthClient $oauth, GmailClient $gmail, IngestMailAction $ingest): void
    {
        $credential = GoogleCredential::first();

        if ($credential === null) {
            throw new \RuntimeException('Gmail と連携されていません（取り込みジョブの実行中に連携が解除された可能性があります）');
        }

        $message = $gmail->fetchMessage($oauth->accessToken($credential), $this->messageId);

        $ingest->execute($message);
    }
}
