<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * gBizINFO REST API（経産省・無料）から認定・補助金・調達実績を取得する。
 * トークン未設定・API失敗は例外で明示する（サイレント失敗禁止）。
 */
class GbizinfoClient
{
    private const BASE = 'https://info.gbiz.go.jp/hojin/v1/hojin';

    /**
     * @return array{certifications: array<int, array{title: string, date: string|null}>, subsidyCount: int, procurementCount: int}
     */
    public function fetch(string $corporateNumber): array
    {
        $certifications = array_values(array_filter(array_map(
            fn (array $c) => [
                'title' => (string) ($c['title'] ?? ''),
                'date' => $c['date_of_approval'] ?? null,
            ],
            $this->list($corporateNumber, 'certification'),
        ), fn (array $c) => $c['title'] !== ''));

        return [
            'certifications' => $certifications,
            'subsidyCount' => count($this->list($corporateNumber, 'subsidy')),
            'procurementCount' => count($this->list($corporateNumber, 'procurement')),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function list(string $corporateNumber, string $kind): array
    {
        $token = config('services.gbizinfo.token');
        if (blank($token)) {
            throw new \RuntimeException(
                'GBIZINFO_API_TOKEN が未設定です（https://info.gbiz.go.jp/hojin/ の「Web-API」から無料で発行できます）'
            );
        }

        $response = Http::timeout(20)
            ->withHeaders(['X-hojinInfo-api-token' => $token, 'Accept' => 'application/json'])
            ->get(self::BASE."/{$corporateNumber}/{$kind}");

        if (in_array($response->status(), [401, 403], true)) {
            throw new \RuntimeException('gBizINFO のAPIトークンが無効です（.env の GBIZINFO_API_TOKEN を確認してください）');
        }

        // 404 はその法人の該当情報が無いことを表す（エラーではない）
        if ($response->status() === 404) {
            return [];
        }

        if (! $response->successful()) {
            throw new \RuntimeException("gBizINFO の取得に失敗しました（HTTP {$response->status()}）");
        }

        return (array) ($response->json("hojin-infos.0.{$kind}") ?? []);
    }
}
