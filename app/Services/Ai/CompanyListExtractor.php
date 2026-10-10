<?php

namespace App\Services\Ai;

use App\Enums\AiPurpose;
use App\Enums\AiTier;

/**
 * 転職サイトの「お気に入り・いいね一覧」等、複数企業が並ぶページの表示テキストから
 * 企業名リストを抽出する。
 */
class CompanyListExtractor
{
    /** 一覧ページはリスト項目が多いため、クリップ判定(8000字)より広く取る */
    private const MAX_CHARS = 20000;

    public function __construct(private readonly AiRunner $ai) {}

    /**
     * @return array<int, string>
     */
    public function extract(string $title, string $url, string $text): array
    {
        // タイトル・URL も閲覧先のページが自由に決められる値なので、本文と同じ境界の内側に入れる
        $page = UntrustedText::wrap(
            "# ページタイトル\n{$title}\n\n# URL\n{$url}\n\n# 表示テキスト\n".mb_substr($text, 0, self::MAX_CHARS),
        );
        $rule = UntrustedText::RULE;

        $prompt = <<<PROMPT
            以下は転職サイトの「お気に入り・いいね・検討中リスト」など、複数企業が並ぶ一覧ページの表示テキストです。掲載されている企業名を抽出してください。

            {$rule}

            - companies: 企業の正式名称に近い形（「株式会社」等を含む）の配列。重複は除く
            - 求人タイトル・職種名・サイトのメニューや広告の文言は含めない
            - 企業名が読み取れないページなら空配列にする

            # ページ内容（タイトル・URL・表示テキスト）
            {$page}
            PROMPT;

        // 名前を拾うだけの機械的な作業なので抽出・判定 Tier で足りる
        /** @var array{companies: array<int, string>} $result */
        $result = $this->ai->runWithSchema(
            $this->schema(),
            $prompt,
            AiTier::Light,
            AiPurpose::ClipList,
        );

        return $result['companies'];
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['companies'],
            'properties' => [
                'companies' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
    }
}
