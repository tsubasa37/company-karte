<?php

namespace Tests\Support;

use App\Enums\AiProvider;
use App\Enums\AiPurpose;
use App\Enums\AiTier;
use App\Models\AiUsage;
use App\Services\Ai\AiRunner;

/**
 * テスト中に本物の AI 実行（codex / API）が起動されるのを防ぐための既定実装。
 *
 * 解決（DI）は普通に通し、実際に呼ばれたときだけ例外にする。
 * 構築時に落とすと、AI を呼ばないテストまで巻き添えで失敗するため。
 */
class ForbiddenAiRunner extends AiRunner
{
    /** 親の依存（設定・ドライバ）を解決させない。呼ばれた時点で例外にするだけなので不要 */
    public function __construct() {}

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function runWithSchema(
        array $schema,
        string $prompt,
        AiTier $tier,
        ?AiPurpose $purpose = null,
        ?string $detail = null,
        ?int $companyId = null,
    ): array {
        throw new \RuntimeException(self::MESSAGE);
    }

    public function probe(AiProvider $provider): ?AiUsage
    {
        throw new \RuntimeException(self::MESSAGE);
    }

    private const MESSAGE = 'テスト中に本物の AI 実行（codex / API）が起動されようとしました（サブスク枠・API 課金を消費します）。'
        .'Queue::fake() の書き忘れか、AiRunner のモック漏れです。';
}
