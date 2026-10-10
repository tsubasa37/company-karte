<?php

namespace App\Services\Edinet;

use App\Models\EdinetDocument;
use App\Models\EdinetScanLog;
use Carbon\CarbonInterface;

/**
 * EDINET の書類一覧を日付単位で走査し、有価証券報告書（docTypeCode=120）だけを
 * ローカルにキャッシュする。走査済みの日付は再走査しない（初回のみ数分かかる）。
 */
class EdinetIndexer
{
    /** 有価証券報告書の書類種別コード */
    private const DOC_TYPE_YUHO = '120';

    public function __construct(private readonly EdinetClient $client) {}

    public function ensureIndexed(CarbonInterface $from, CarbonInterface $to): void
    {
        // scan_date は datetime として保存されるため文字列 whereBetween では取りこぼす。
        // テーブルは高々数百行なので全件を日付文字列に直して照合する。
        $scanned = EdinetScanLog::query()
            ->pluck('scan_date')
            ->map(fn ($d) => $d->toDateString())
            ->all();

        for ($date = $from->copy(); $date->lte($to); $date = $date->addDay()) {
            $dateString = $date->toDateString();
            if (in_array($dateString, $scanned, true)) {
                continue;
            }

            foreach ($this->client->documentsFor($dateString) as $doc) {
                if (($doc['docTypeCode'] ?? null) !== self::DOC_TYPE_YUHO) {
                    continue;
                }

                EdinetDocument::updateOrCreate(
                    ['doc_id' => (string) $doc['docID']],
                    [
                        'doc_date' => $dateString,
                        'edinet_code' => $doc['edinetCode'] ?? null,
                        'jcn' => $doc['JCN'] ?? null,
                        'period_end' => $doc['periodEnd'] ?? null,
                        'filer_name' => $doc['filerName'] ?? null,
                    ],
                );
            }

            EdinetScanLog::create(['scan_date' => $dateString]);
        }
    }
}
