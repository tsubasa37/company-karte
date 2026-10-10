<?php

namespace App\Actions;

use App\Enums\MailKind;
use App\Enums\ScheduleKind;
use App\Enums\ScheduleSource;
use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Models\Company;
use App\Models\MailIngest;
use App\Models\ScheduleEvent;
use App\Models\SourceDocument;
use App\Services\Ai\MailExtractor;
use App\Services\CompanyNameNormalizer;
use App\Services\Mail\FromDomainAllowList;
use App\Services\Mail\MailMasker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * メール1通を取り込み、企業・ソース・予定を自動登録する。
 *
 * 自動で登録するが、由来（📧バッジ）と取り込み履歴を必ず残す。
 * 承認ゲートは置かない代わりに、間違いに気づいて取り消せる形にしている。
 *
 * codex に渡すのはマスク済みの件名・本文のみ。原文はローカルの source_documents にだけ残す。
 */
class IngestMailAction
{
    /** codex が返す日時の形式。ここに合わないものは予定を作らず候補として残す */
    private const STARTS_AT_FORMAT = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/';

    /** 失敗理由として履歴に残す長さの上限 */
    private const MAX_ERROR_LENGTH = 2000;

    public function __construct(
        private readonly FromDomainAllowList $allowList,
        private readonly MailMasker $masker,
        private readonly MailExtractor $extractor,
        private readonly CompanyNameNormalizer $names,
        private readonly UpsertSourceDocumentAction $upsert,
    ) {}

    /**
     * @param  array{id: string, from: string, from_domain: string, subject: string, received_at: Carbon, body: string}  $message
     * @return MailIngest|null 取り込まなかった場合は null（重複・許可ドメイン外）
     */
    public function execute(array $message): ?MailIngest
    {
        if (MailIngest::where('external_id', $message['id'])->exists()) {
            return null;
        }

        // Gmail のクエリで絞った上での二重の歯止め（クエリの書き間違いを取り込みで拾わない）
        if (! $this->allowList->allows($message['from_domain'])) {
            return null;
        }

        try {
            $extracted = $this->extractor->extract(
                $this->masker->mask($message['subject']),
                $this->masker->mask($message['body']),
                $message['received_at']->format('Y-m-d H:i'),
            );

            if (! $extracted['is_job_related'] || blank(trim($extracted['company_name']))) {
                // 判定済みであることを残す（次回の取得で同じメールに codex を使い直さない）
                return $this->record($message, $extracted, null, null, null, false);
            }

            // 企業・ソース・予定・履歴は一括で成立させる。途中で失敗したときに
            // 履歴から追跡・取り消しできない孤児レコードを残さない
            return DB::transaction(function () use ($message, $extracted) {
                [$company, $created] = $this->company($extracted['company_name']);
                $source = $this->storeSource($company, $message, $extracted);
                $event = $this->storeEvent($company, $extracted);
                $this->fillPosition($company, $extracted['position']);

                return $this->record($message, $extracted, $company, $source->id, $event?->id, $created);
            });
        } catch (\Throwable $e) {
            // AI を呼んだ後の失敗は「取り込み失敗」として履歴に残す。残さないと次回の取得が同じメールを
            // また積み、15分ごとに AI を使い直す。失敗そのものは握り潰さず再送出する（failed_jobs にも残る）
            $this->recordFailure($message, $e);

            throw $e;
        }
    }

    /**
     * @param  array{id: string, from_domain: string, subject: string, received_at: Carbon}  $message
     */
    private function recordFailure(array $message, \Throwable $e): void
    {
        try {
            MailIngest::create([
                'external_id' => $message['id'],
                'from_domain' => $message['from_domain'],
                'subject' => mb_substr($message['subject'], 0, 255),
                'received_at' => $message['received_at'],
                'mail_kind' => MailKind::Other,
                'error' => mb_substr($e->getMessage(), 0, self::MAX_ERROR_LENGTH),
            ]);
        } catch (\Throwable $inner) {
            throw new \RuntimeException(
                '取り込みに失敗し、その失敗の記録にも失敗しました: '.$inner->getMessage().' / 元のエラー: '.$e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * 未設定のときだけ募集ポジションを埋める。
     * 本人が入力した値や、より新しい求人票由来の値を古いスカウトメールで上書きしない。
     */
    private function fillPosition(Company $company, string $position): void
    {
        if (blank(trim($position)) || filled($company->position)) {
            return;
        }

        $company->update(['position' => mb_substr(trim($position), 0, 255)]);
    }

    /**
     * 既存企業に名寄せし、無ければ作る（クリップの一括登録と同じ判定基準）。
     *
     * @return array{0: Company, 1: bool} 企業と、この取り込みで新規作成したか
     */
    private function company(string $name): array
    {
        $existing = $this->names->findExisting($name);

        if ($existing !== null) {
            return [$existing, false];
        }

        // codex の出力なので長さを信用しない（他の抽出項目と同じく上限を掛ける）
        return [Company::create(['name' => mb_substr(trim($name), 0, 255)]), true];
    }

    /**
     * メール本文をソースとして残す。保存するのは**マスク前の原文**
     * （読むのは本人であり、伏せる必要がない。codex に渡したのはマスク後のみ）。
     *
     * @param  array{id: string, subject: string, received_at: Carbon, body: string}  $message
     * @param  array<string, mixed>  $extracted
     */
    private function storeSource(Company $company, array $message, array $extracted): SourceDocument
    {
        return $this->upsert->execute($company, [
            'type' => SourceType::Scout->value,
            'origin' => SourceOrigin::Mail->value,
            'url' => null,
            'title' => mb_substr($message['subject'], 0, 255),
            'summary' => mb_substr($extracted['summary'], 0, 255),
            'raw_text' => $message['body'],
            'fetched_at' => $message['received_at'],
        ]);
    }

    /**
     * 日時候補がちょうど1件で、その日時が実在するときだけ予定を作る。
     *
     * 複数候補（「以下からご希望の日時を」型）はアプリが勝手に1つ選ばない。
     * 判定は**絞り込む前の候補数**で行う。形式の壊れた候補を先に捨てると、
     * 「2件提示されたうち1件がパース不能」のときに残り1件を選んだことになってしまう。
     *
     * @param  array<string, mixed>  $extracted
     */
    private function storeEvent(Company $company, array $extracted): ?ScheduleEvent
    {
        $candidates = $extracted['event_candidates'];

        if (count($candidates) !== 1) {
            return null;
        }

        $candidate = $candidates[0];
        $startsAt = $this->parseStartsAt((string) ($candidate['starts_at'] ?? ''));

        if ($startsAt === null) {
            return null;
        }

        return $company->scheduleEvents()->create([
            'source' => ScheduleSource::Mail,
            'kind' => ScheduleKind::from($candidate['kind']),
            'title' => blank($candidate['title']) ? null : mb_substr($candidate['title'], 0, 255),
            'starts_at' => $startsAt,
            'place' => blank($candidate['place']) ? null : mb_substr($candidate['place'], 0, 255),
        ]);
    }

    /**
     * 'Y-m-d H:i' として**実在する**日時だけを返す。妥当でなければ null。
     *
     * createFromFormat は "2026-02-30 25:00" を例外にせず "2026-03-03 01:00" へ繰り上げる。
     * 書式の一致だけを見ると、codex が月末の計算を誤ったときに別の日時の予定が黙って作られる。
     * 書式で弾いたうえで、戻した文字列が元と一致するかまで確かめる。
     */
    private function parseStartsAt(string $value): ?Carbon
    {
        if (preg_match(self::STARTS_AT_FORMAT, $value) !== 1) {
            return null;
        }

        // '!' で秒以下を 0 に固定する（現在時刻が混ざらないようにする）
        $parsed = Carbon::createFromFormat('!Y-m-d H:i', $value);

        return $parsed->format('Y-m-d H:i') === $value ? $parsed : null;
    }

    /**
     * @param  array{id: string, from_domain: string, subject: string, received_at: Carbon}  $message
     * @param  array<string, mixed>  $extracted
     */
    private function record(
        array $message,
        array $extracted,
        ?Company $company,
        ?int $sourceId,
        ?int $eventId,
        bool $createdCompany,
    ): MailIngest {
        return MailIngest::create([
            'external_id' => $message['id'],
            'from_domain' => $message['from_domain'],
            'subject' => mb_substr($message['subject'], 0, 255),
            'received_at' => $message['received_at'],
            'mail_kind' => MailKind::from($extracted['mail_kind']),
            'summary' => mb_substr($extracted['summary'], 0, 255),
            'company_id' => $company?->id,
            'source_document_id' => $sourceId,
            'schedule_event_id' => $eventId,
            'created_company' => $createdCompany,
            // 予定を作った場合は候補を残さない（作らなかったときだけ画面で選ばせる）
            'event_candidates' => $eventId === null ? $extracted['event_candidates'] : null,
        ]);
    }
}
