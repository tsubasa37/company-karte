<?php

namespace App\Actions;

use App\Models\GoogleCredential;
use App\Models\MailIngest;
use App\Services\Mail\MailAutoFetch;
use App\Services\ScheduleEventPresenter;

/**
 * メール取り込み履歴ページの描画データ。
 * 「何を作ったか」「未確認か」「失敗したか」を一覧できるようにし、誤登録を見つけて取り消せる・失敗をやり直せるようにする。
 */
class BuildMailPageAction
{
    /** 履歴に出す件数（自分用途なのでこれで足りる） */
    private const LIMIT = 100;

    public function __construct(
        private readonly ScheduleEventPresenter $presenter,
        private readonly MailAutoFetch $autoFetch,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $ingests = MailIngest::query()
            ->with(['company', 'scheduleEvent.company', 'sourceDocument'])
            ->recent()
            ->limit(self::LIMIT)
            ->get();

        return [
            'ingests' => $ingests->map($this->row(...))->all(),
            'unreviewedCount' => MailIngest::where('reviewed', false)->count(),
            // 取り込みに失敗したまま残っている件数（まとめて再試行のボタンに使う）
            'failedCount' => MailIngest::whereNotNull('error')->count(),
            // 取得処理そのものの直近の失敗（成功すると消える）
            'fetchError' => $this->fetchError(),
            // 取り込み方法を画面の説明文に反映する（既定は手動なので「15分おき」と書けない）
            'autoFetch' => $this->autoFetch->isEnabled(),
        ];
    }

    /**
     * @return array{message: string, at: string|null}|null
     */
    private function fetchError(): ?array
    {
        $credential = GoogleCredential::query()->first();

        if ($credential?->last_fetch_error === null) {
            return null;
        }

        return [
            'message' => $credential->last_fetch_error,
            'at' => $credential->last_fetch_error_at?->format('Y-m-d H:i'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(MailIngest $ingest): array
    {
        return [
            'id' => $ingest->id,
            'subject' => $ingest->subject,
            'fromDomain' => $ingest->from_domain,
            'receivedAt' => $ingest->received_at->format('Y-m-d H:i'),
            'mailKind' => $ingest->mail_kind->value,
            'mailKindLabel' => $ingest->mail_kind->label(),
            'summary' => $ingest->summary,
            'companyId' => $ingest->company_id,
            'companyName' => $ingest->company?->name,
            'sourceId' => $ingest->source_document_id,
            // 予定を自動作成できた場合はその内容、できなかった場合は候補を出す
            'scheduleEvent' => $ingest->scheduleEvent !== null
                ? $this->presenter->present($ingest->scheduleEvent)
                : null,
            'eventCandidates' => $ingest->event_candidates ?? [],
            'reviewed' => $ingest->reviewed,
            // 取り込み失敗（AI を通した後に登録で失敗）。理由を出し、やり直しはこの行のボタンから
            'failed' => $ingest->error !== null,
            'error' => $ingest->error,
            // 取り消せるのは、まだ何か作ったものが残っているときだけ
            'revertible' => $ingest->company_id !== null
                || $ingest->source_document_id !== null
                || $ingest->schedule_event_id !== null,
        ];
    }
}
