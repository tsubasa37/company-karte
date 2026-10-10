<?php

namespace Tests\Support;

use App\Enums\AiPurpose;
use App\Models\AiUsage;
use App\Services\Ai\AiDecider;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Decision\DecisionRequest;
use App\Services\Ai\Decision\DecisionResponse;

/**
 * テスト中に本物の判定 API（Jev）が呼ばれるのを防ぐための既定実装（ForbiddenAiRunner と同じ流儀）。
 *
 * 解決（DI）は普通に通し、実際に判定が呼ばれたときだけ例外にする。
 * 構築時に落とすと、判定を呼ばないテストまで巻き添えで失敗するため。
 */
class ForbiddenAiDecider extends AiDecider
{
    /** 親の依存（設定・ドライバ）を解決させない。呼ばれた時点で例外にするだけなので不要 */
    public function __construct() {}

    /**
     * 機能のオン・オフは本物の設定で答える（既定はオフ）。オンにしたテストが decide まで進めば下の例外で止まる。
     * 親の実装は注入された依存を読むため、ここでコンテナから引く。
     */
    public function isConfigured(): bool
    {
        return app(AiSettings::class)->isDecisionConfigured();
    }

    public function decide(DecisionRequest $request, AiPurpose $purpose, ?string $detail = null, ?int $companyId = null): DecisionResponse
    {
        throw new \RuntimeException(self::MESSAGE);
    }

    public function decideMany(array $requests, AiPurpose $purpose, ?string $detail = null, ?int $companyId = null): array
    {
        throw new \RuntimeException(self::MESSAGE);
    }

    public function probe(): ?AiUsage
    {
        throw new \RuntimeException(self::MESSAGE);
    }

    private const MESSAGE = 'テスト中に本物の判定 API（Jev）が呼ばれようとしました（API 課金を消費します）。'
        .'AiDecider のモック漏れです。';
}
