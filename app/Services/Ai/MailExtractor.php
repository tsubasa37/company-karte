<?php

namespace App\Services\Ai;

use App\Enums\AiPurpose;
use App\Enums\AiTier;

/**
 * 取り込んだメールから「どの企業の何のメールか」「面接日時の候補」を codex で抽出する。
 *
 * 渡すのはマスク済みの件名・本文だけ（MailMasker 適用後）。ヘッダ・宛先・添付は渡さない。
 * メールは第三者が自由に書けるため、本文は UntrustedText の境界の内側に入れる。
 */
class MailExtractor
{
    private const MAX_CHARS = 8000;

    public function __construct(private readonly AiRunner $ai) {}

    /**
     * @param  string  $receivedAt  受信日時 'Y-m-d H:i'。「来週火曜」等の相対表現を解決する基準
     * @return array{is_job_related: bool, company_name: string, mail_kind: string, position: string, event_candidates: array<int, array{starts_at: string, kind: string, title: string, place: string}>, summary: string}
     */
    public function extract(string $subject, string $body, string $receivedAt): array
    {
        // 件名も差出人が自由に決められる値なので、本文と同じ境界の内側に入れる
        $mail = UntrustedText::wrap(
            "# 件名\n{$subject}\n\n# 本文\n".mb_substr($body, 0, self::MAX_CHARS),
        );
        $rule = UntrustedText::RULE;

        $prompt = <<<PROMPT
            以下は転職活動中に受信したメールです。企業カルテに登録するため、内容を判定してください。

            {$rule}

            - is_job_related: 転職に関わるメール（スカウト / エージェントからの案内 / 面接の日程調整 / 選考結果）なら true。広告・メルマガ・無関係なものは false。
            - company_name: 対象企業の正式名称に近い形（「株式会社」等を含む）。求人媒体名（ビズリーチ等）ではなく、募集している企業名。分からなければ空文字。
            - mail_kind: scout(スカウト・案内) / schedule(面接や面談の日程調整) / result(選考結果の通知) / other
            - position: 募集ポジション名。書かれていなければ空文字。
            - event_candidates: 面接・面談の日時候補。提示された日時ごとに1件。日時が書かれていなければ空配列。
              - starts_at: 「YYYY-MM-DD HH:MM」形式。日本時間。「来週火曜15時」のような相対表現はこのメールの受信日時 {$receivedAt} を基準に解決する。年が省略されていれば受信日時から補う。時刻が不明なら省略せず 00:00 とする。
              - kind: casual(カジュアル面談) / interview(面接) / other
              - title: 「1次」「最終」など回次が分かる語。不明なら空文字。
              - place: オンラインのURL、または対面の場所。不明なら空文字。
            - summary: 何のメールか日本語で1文。

            # メール（件名・本文）
            {$mail}
            PROMPT;

        // 企業名と日時を拾うだけの判定なので抽出・判定 Tier で実行する
        /** @var array{is_job_related: bool, company_name: string, mail_kind: string, position: string, event_candidates: array<int, array{starts_at: string, kind: string, title: string, place: string}>, summary: string} */
        return $this->ai->runWithSchema(
            $this->schema(),
            $prompt,
            AiTier::Light,
            AiPurpose::MailExtract,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['is_job_related', 'company_name', 'mail_kind', 'position', 'event_candidates', 'summary'],
            'properties' => [
                'is_job_related' => ['type' => 'boolean'],
                'company_name' => ['type' => 'string'],
                'mail_kind' => ['type' => 'string', 'enum' => ['scout', 'schedule', 'result', 'other']],
                'position' => ['type' => 'string'],
                'event_candidates' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['starts_at', 'kind', 'title', 'place'],
                        'properties' => [
                            'starts_at' => ['type' => 'string'],
                            'kind' => ['type' => 'string', 'enum' => ['casual', 'interview', 'other']],
                            'title' => ['type' => 'string'],
                            'place' => ['type' => 'string'],
                        ],
                    ],
                ],
                'summary' => ['type' => 'string'],
            ],
        ];
    }
}
