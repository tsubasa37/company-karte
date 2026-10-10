<?php

namespace App\Actions;

use App\Models\Company;
use App\Services\Ai\CompanyListExtractor;
use App\Services\CompanyNameNormalizer;

/**
 * 「お気に入り・いいね一覧」ページから企業名を抽出し、未登録の企業だけ一括登録する。
 * ソースは登録しない（一覧ページは1社の分析材料にならないため）。
 * 各社の求人票・口コミは個別ページのクリップで集める。
 */
class IngestClipListAction
{
    /**
     * 1回の一括登録で作る企業数の上限。
     * 抽出結果は AI の出力なので、注入されたページに大量の名前を吐かされても
     * 1クリックで企業テーブルが埋まらないようにする（IngestMailAction と同じく出力を信用しない）。
     */
    private const MAX_COMPANIES = 100;

    /** 社名の長さ上限。companies.name は string(255) で、メール経路も同じ値で切っている */
    private const MAX_NAME_LENGTH = 255;

    public function __construct(
        private readonly CompanyListExtractor $extractor,
        private readonly CompanyNameNormalizer $names,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(string $title, string $url, string $text): array
    {
        $extracted = $this->extractor->extract($title, $url, $text);

        $created = [];
        $existing = [];
        // 上限に達して登録しなかった件数。黙って捨てず、結果に添えて本人に伝える
        $skipped = 0;

        foreach ($extracted as $name) {
            $name = mb_substr(trim($name), 0, self::MAX_NAME_LENGTH);
            if ($name === '') {
                continue;
            }

            if ($this->names->findExisting($name) !== null) {
                $existing[] = $name;

                continue;
            }

            if (count($created) >= self::MAX_COMPANIES) {
                $skipped++;

                continue;
            }

            Company::create(['name' => $name]);
            $created[] = $name;
        }

        if ($created === [] && $existing === []) {
            return [
                'ok' => false,
                'message' => '企業名を抽出できませんでした（企業が並ぶ一覧ページか確認してください）',
            ];
        }

        return ['ok' => true, 'created' => $created, 'existing' => $existing, 'skipped' => $skipped];
    }
}
