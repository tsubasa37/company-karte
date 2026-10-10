<?php

namespace App\Services\Ai;

use App\Enums\AiPurpose;
use App\Enums\AiTier;

/**
 * 口コミページの表示テキストを「1件の口コミ = 1要素」に分割する。
 * サイトのナビゲーション・広告・定型文を除き、本文は原文のまま抜き出す（要約しない）。
 */
class ReviewSplitter
{
    /**
     * 口コミページは1ページに複数件が並ぶため、クリップ判定(8000字)より広く取る。
     * これを超える分は分割対象にならない（通常の口コミページはこの範囲に収まる想定）。
     * 画面表示（ソース詳細の注記）にも使う。
     */
    public const MAX_CHARS = 20000;

    public function __construct(private readonly AiRunner $ai) {}

    /**
     * @return array<int, array{body: string, stance: string, posted_period: string, tenure_period: string, category: string, job_type: string}>
     */
    public function split(string $text, ?int $companyId = null): array
    {
        $body = UntrustedText::wrap(mb_substr($text, 0, self::MAX_CHARS));
        $rule = UntrustedText::RULE;

        $prompt = <<<PROMPT
            以下は口コミサイトのページに表示されていたテキストです。個々の口コミ（1人の投稿者による1つの投稿）を抽出してください。

            {$rule}

            - body: 口コミ本文。原文のまま抜き出す（要約・言い換えをしない）。サイトのメニュー・広告・「回答する」等の定型文は含めない
            - stance: 投稿者の立場。「現職」「退職」が読み取れればそれ、読み取れなければ「不明」
            - posted_period: その口コミが**投稿・回答された時期**（投稿日 / 回答日 / 掲載日の表記。例: 2026年01月 / 回答日 2025年3月）。不明なら空文字
            - tenure_period: 投稿者が**在籍していた時期**（例: 2020年〜2024年 / 2010年代前半 / 在籍3年）。不明なら空文字
            - posted_period と tenure_period は必ず書き分ける。投稿日しか無いなら tenure_period は空文字、在籍時期しか無いなら posted_period は空文字にする
            - category: サイト上のカテゴリ表記があれば（例: 年収・評価 / ワークライフバランス）。無ければ空文字
            - job_type: 投稿者の職種が読み取れれば（例: エンジニア / 営業）。不明なら空文字
            - 口コミが1件も無いページなら reviews は空配列にする

            # ページテキスト
            {$body}
            PROMPT;

        // 原文の抜き出しと属性の読み取りだけの機械的な作業なので、抽出・判定 Tier で足りる
        /** @var array{reviews: array<int, array{body: string, stance: string, posted_period: string, tenure_period: string, category: string, job_type: string}>} $result */
        $result = $this->ai->runWithSchema(
            $this->schema(),
            $prompt,
            AiTier::Light,
            AiPurpose::ReviewSplit,
            null,
            $companyId,
        );

        return $result['reviews'];
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['reviews'],
            'properties' => [
                'reviews' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['body', 'stance', 'posted_period', 'tenure_period', 'category', 'job_type'],
                        'properties' => [
                            'body' => ['type' => 'string'],
                            'stance' => ['type' => 'string', 'enum' => ['現職', '退職', '不明']],
                            'posted_period' => ['type' => 'string'],
                            'tenure_period' => ['type' => 'string'],
                            'category' => ['type' => 'string'],
                            'job_type' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
