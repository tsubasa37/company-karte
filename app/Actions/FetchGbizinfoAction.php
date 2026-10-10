<?php

namespace App\Actions;

use App\Enums\MetricSource;
use App\Models\Company;
use App\Services\GbizinfoClient;

/**
 * gBizINFO から認定（くるみん/えるぼし等）・補助金・調達実績を取得し、
 * 公式数値（official_metrics, source=gbizinfo）として登録する。
 * 求人票の子育て支援・働き方系の主張の裏取り材料になる。
 */
class FetchGbizinfoAction
{
    public function __construct(private readonly GbizinfoClient $client) {}

    public function execute(Company $company): void
    {
        if (blank($company->legal_number)) {
            throw new \InvalidArgumentException('法人番号が未設定です。「基本情報・メモ」タブで法人番号を入力してください。');
        }

        // API の URL パスへ連結する値なので、13桁の数字であることを先に確定する
        // （手入力欄のため、ハイフン混じりや別の文字列が入り得る）
        if (! preg_match('/^\d{13}$/', (string) $company->legal_number)) {
            throw new \InvalidArgumentException(
                '法人番号は13桁の数字で入力してください（現在の値: '.$company->legal_number.'）'
            );
        }

        $data = $this->client->fetch($company->legal_number);
        $sourceUrl = 'https://info.gbiz.go.jp/hojin/ichiran?hojinBango='.$company->legal_number;

        foreach ($data['certifications'] as $cert) {
            $company->officialMetrics()->updateOrCreate(
                ['source' => MetricSource::Gbizinfo->value, 'key' => '認定: '.$cert['title']],
                [
                    'value' => 'あり',
                    'fiscal_year' => $cert['date'] ? mb_substr((string) $cert['date'], 0, 4) : null,
                    'source_url' => $sourceUrl,
                ],
            );
        }

        // 0件も「実績なし」という情報として登録する
        $company->officialMetrics()->updateOrCreate(
            ['source' => MetricSource::Gbizinfo->value, 'key' => '補助金交付'],
            ['value' => (string) $data['subsidyCount'], 'unit' => '件', 'source_url' => $sourceUrl],
        );
        $company->officialMetrics()->updateOrCreate(
            ['source' => MetricSource::Gbizinfo->value, 'key' => '国の調達実績'],
            ['value' => (string) $data['procurementCount'], 'unit' => '件', 'source_url' => $sourceUrl],
        );
    }
}
