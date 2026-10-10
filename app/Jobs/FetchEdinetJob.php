<?php

namespace App\Jobs;

use App\Enums\MetricSource;
use App\Jobs\Concerns\AssertsQueueHeadroom;
use App\Models\Company;
use App\Models\EdinetDocument;
use App\Services\Edinet\EdinetClient;
use App\Services\Edinet\EdinetDocumentUrl;
use App\Services\Edinet\EdinetIndexer;
use App\Services\Edinet\EdinetYuhoParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/**
 * 企業の有価証券報告書から平均年収等を取得し、公式数値に登録する。
 * 初回はEDINETの書類一覧を過去400日ぶん走査するため数分かかる（以降はキャッシュ）。
 * 失敗は companies.edinet_error に残して明示する。
 *
 * 有報CSVには当期と前期以前の行が入るため、年度（fiscal_year）別に登録して推移を残す。
 */
class FetchEdinetJob implements ShouldQueue
{
    use AssertsQueueHeadroom;
    use Queueable;

    /** 有報は年1回提出のため、直近400日を見れば最新の1件を必ず含む */
    private const SCAN_DAYS = 400;

    /**
     * CSVを読む書類数の上限。400日の走査には提出年の異なる有報が2件入ることがあり、
     * 複数年ぶんを登録するため最新1件に絞らない。ただしCSVは1件あたり数MBなので上限を置く。
     */
    private const MAX_DOCUMENTS = 3;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $companyId)
    {
        $this->assertQueueHeadroom();
    }

    public function handle(EdinetIndexer $indexer, EdinetClient $client, EdinetYuhoParser $parser): void
    {
        $company = Company::findOrFail($this->companyId);

        try {
            $this->fetch($company, $indexer, $client, $parser);
        } catch (\Throwable $e) {
            $company->update(['edinet_error' => $e->getMessage(), 'edinet_requested_at' => null]);

            throw $e;
        }
    }

    private function fetch(Company $company, EdinetIndexer $indexer, EdinetClient $client, EdinetYuhoParser $parser): void
    {
        if (blank($company->legal_number)) {
            throw new \RuntimeException('法人番号が未設定です（EDINETは法人番号で照合します）');
        }

        // 当日は提出が増え続けるため走査対象にしない（昨日まで）
        $indexer->ensureIndexed(now()->subDays(self::SCAN_DAYS), now()->subDay());

        $docs = $this->documentsOf($company);

        if ($docs->isEmpty()) {
            throw new \RuntimeException('直近400日のEDINETに有価証券報告書が見つかりません（未上場・法人番号違いの可能性）');
        }

        $company->update(['edinet_code' => $docs->first()->edinet_code]);

        // 古い書類から順に登録する。同じ年度が複数の書類に載っている場合
        // （新しい有報の前期 = 古い有報の当期）は新しい書類の値を残す
        $registered = 0;
        foreach ($docs->reverse() as $doc) {
            $registered += $this->register($company, $doc, $client, $parser);
        }

        if ($registered === 0) {
            throw new \RuntimeException('有報CSVから対象項目（平均年間給与等）を抽出できませんでした');
        }

        $company->update([
            'edinet_fetched_at' => now(),
            'edinet_error' => null,
            'edinet_requested_at' => null,
        ]);
    }

    /**
     * 対象企業の有報（新しい順）。
     *
     * @return Collection<int, EdinetDocument>
     */
    private function documentsOf(Company $company): Collection
    {
        return EdinetDocument::query()
            ->where('jcn', $company->legal_number)
            ->orderByDesc('doc_date')
            ->limit(self::MAX_DOCUMENTS)
            ->get();
    }

    /** 1書類ぶんの数値を年度別に登録し、登録件数を返す */
    private function register(Company $company, EdinetDocument $doc, EdinetClient $client, EdinetYuhoParser $parser): int
    {
        $fiscalYear = $this->fiscalYearOf($doc);
        $sourceUrl = EdinetDocumentUrl::viewer($doc->doc_id);

        $metrics = [];
        foreach ($client->documentCsvTexts($doc->doc_id) as $csv) {
            $metrics = array_merge($metrics, $parser->metrics($csv, $fiscalYear));
        }

        foreach ($metrics as $m) {
            // 一意キーに年度を含める。含めないと翌年の取得で前年の値が上書きされ推移が残らない
            $company->officialMetrics()->updateOrCreate(
                [
                    'source' => MetricSource::Edinet->value,
                    'key' => $m['key'],
                    'fiscal_year' => $m['fiscal_year'],
                ],
                [
                    'value' => $m['value'],
                    'unit' => $m['unit'],
                    'source_url' => $sourceUrl,
                ],
            );
        }

        return count($metrics);
    }

    /**
     * 書類の決算期から当期の年度を決める。
     * 決算期が取れない書類は登録しない（年度なしで混ぜると別の年度の値を上書きしてしまう）。
     */
    private function fiscalYearOf(EdinetDocument $doc): string
    {
        if (blank($doc->period_end)) {
            throw new \RuntimeException(
                "EDINET書類の決算期（periodEnd）が無いため年度を確定できません（{$doc->doc_id}）"
            );
        }

        return mb_substr((string) $doc->period_end, 0, 4);
    }

    public function failed(\Throwable $e): void
    {
        Company::find($this->companyId)?->update([
            'edinet_error' => $e->getMessage(),
            'edinet_requested_at' => null,
        ]);
    }
}
