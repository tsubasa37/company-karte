<?php

namespace Tests\Feature;

use App\Jobs\FetchEdinetJob;
use App\Models\Company;
use App\Models\EdinetDocument;
use App\Models\OfficialMetric;
use App\Services\Edinet\EdinetClient;
use App\Services\Edinet\EdinetIndexer;
use App\Services\Edinet\EdinetYuhoParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class EdinetTest extends TestCase
{
    use RefreshDatabase;

    /** 2026年3月期の有報CSV（当期のほかに前期の従業員数も載っている） */
    private function sampleCsv(): string
    {
        $rows = [
            "要素ID\t項目名\tコンテキストID\t相対年度\t連結・個別\t期間・時点\tユニットID\t単位\t値",
            "jpcrp_cor:AverageAnnualSalaryInformationAboutReportingCompanyInformationAboutEmployees\t平均年間給与\tCurrentYearInstant\t当期\t個別\t時点\tJPY\t円\t6297000",
            "jpcrp_cor:AverageAnnualSalaryInformationAboutReportingCompanyInformationAboutEmployees\t平均年間給与\tPrior1YearInstant\t前期\t個別\t時点\tJPY\t円\t6100000",
            "jpcrp_cor:AverageAgeYearsInformationAboutReportingCompanyInformationAboutEmployees\t平均年齢\tCurrentYearInstant\t当期\t個別\t時点\tpure\t歳\t42.3",
            "jpcrp_cor:AverageLengthOfServiceYearsInformationAboutReportingCompanyInformationAboutEmployees\t平均勤続年数\tCurrentYearInstant\t当期\t個別\t時点\tpure\t年\t8.2",
            "jpcrp_cor:NumberOfEmployees\t従業員数\tCurrentYearInstant_NonConsolidatedMember\t当期\t個別\t時点\tpure\t人\t524",
            "jpcrp_cor:NumberOfEmployees\t従業員数\tCurrentYearInstant\t当期\t連結\t時点\tpure\t人\t1200",
            "jpcrp_cor:NumberOfEmployees\t従業員数\tPrior1YearInstant_NonConsolidatedMember\t前期\t個別\t時点\tpure\t人\t500",
        ];

        return implode("\n", $rows);
    }

    /** 2025年3月期の有報CSV（当期ぶんのみ） */
    private function priorDocCsv(): string
    {
        $rows = [
            "要素ID\t項目名\tコンテキストID\t相対年度\t連結・個別\t期間・時点\tユニットID\t単位\t値",
            "jpcrp_cor:AverageAnnualSalaryInformationAboutReportingCompanyInformationAboutEmployees\t平均年間給与\tCurrentYearInstant\t当期\t個別\t時点\tJPY\t円\t5900000",
            "jpcrp_cor:NumberOfEmployees\t従業員数\tCurrentYearInstant_NonConsolidatedMember\t当期\t個別\t時点\tpure\t人\t505",
        ];

        return implode("\n", $rows);
    }

    public function test_有報_cs_vから従業員関連の数値を年度別に抽出する(): void
    {
        $metrics = collect((new EdinetYuhoParser)->metrics($this->sampleCsv(), '2026'))
            ->keyBy(fn (array $m) => $m['key'].'/'.$m['fiscal_year']);

        $this->assertSame('630', $metrics['平均年間給与/2026']['value'], '円→万円へPHPで確定変換');
        $this->assertSame('610', $metrics['平均年間給与/2025']['value'], '前期の行も年度を付けて残す');
        $this->assertSame('42.3', $metrics['平均年齢/2026']['value']);
        $this->assertSame('8.2', $metrics['平均勤続年数/2026']['value']);
        $this->assertSame('524', $metrics['従業員数(単体)/2026']['value'], '連結でなく提出会社の当期を取る');
        $this->assertSame('500', $metrics['従業員数(単体)/2025']['value']);
    }

    public function test_インデクサは有報だけ保存し走査済みの日を再走査しない(): void
    {
        config(['services.edinet.key' => 'test-key']);
        Http::fake([
            'api.edinet-fsa.go.jp/*' => Http::response(['results' => [
                ['docID' => 'S100AAAA', 'docTypeCode' => '120', 'edinetCode' => 'E00001',
                    'JCN' => '1234567890123', 'periodEnd' => '2026-03-31', 'filerName' => 'テスト株式会社'],
                ['docID' => 'S100BBBB', 'docTypeCode' => '030'],
            ]]),
        ]);

        $indexer = $this->app->make(EdinetIndexer::class);
        $day = Carbon::parse('2026-07-20');

        $indexer->ensureIndexed($day, $day);
        $indexer->ensureIndexed($day, $day);

        $this->assertSame(1, EdinetDocument::count(), '有報(120)のみ保存される');
        Http::assertSentCount(1);
    }

    public function test_取得開始のガード(): void
    {
        Queue::fake();
        config(['services.edinet.key' => 'test-key']);

        $unlisted = Company::create(['name' => '未上場社', 'listed' => false, 'legal_number' => '1234567890123']);
        $this->post(route('edinet.store', $unlisted))->assertSessionHasErrors('edinet');

        $noNumber = Company::create(['name' => '番号なし社', 'listed' => true]);
        $this->post(route('edinet.store', $noNumber))->assertSessionHasErrors('edinet');

        $ok = Company::create(['name' => '上場社', 'listed' => true, 'legal_number' => '1234567890123']);
        $this->post(route('edinet.store', $ok))->assertSessionHasNoErrors();

        Queue::assertPushed(FetchEdinetJob::class);
        $this->assertNotNull($ok->fresh()->edinet_requested_at);

        // 取得中の再要求は弾かれる（初回400日走査の二重実行を防ぐ）
        $this->post(route('edinet.store', $ok))->assertSessionHasErrors('edinet');
        Queue::assertPushed(FetchEdinetJob::class, 1);
    }

    public function test_ジョブが複数年度の有報の数値を年度別に登録する(): void
    {
        $company = Company::create(['name' => 'テスト株式会社', 'listed' => true, 'legal_number' => '1234567890123']);
        $this->createDocument('S100OLD1', '2025-06-25', '2025-03-31');
        $this->createDocument('S100NEW1', '2026-06-25', '2026-03-31');

        $indexer = Mockery::mock(EdinetIndexer::class);
        $indexer->shouldReceive('ensureIndexed')->once();
        $client = Mockery::mock(EdinetClient::class);
        $client->shouldReceive('documentCsvTexts')->with('S100NEW1')->andReturn([$this->sampleCsv()]);
        $client->shouldReceive('documentCsvTexts')->with('S100OLD1')->andReturn([$this->priorDocCsv()]);

        (new FetchEdinetJob($company->id))->handle($indexer, $client, new EdinetYuhoParser);

        $salaries = OfficialMetric::where('key', '平均年間給与')->orderByDesc('fiscal_year')->get();
        $this->assertSame(['2026', '2025'], $salaries->pluck('fiscal_year')->all(), '年度違いで2行残る（上書きされない）');
        $this->assertSame(
            ['630', '610'],
            $salaries->pluck('value')->all(),
            '同じ年度が2つの書類に載る場合は新しい書類（前期欄）の値を残す',
        );
        $this->assertSame(6, OfficialMetric::count(), '2026: 給与/年齢/勤続/従業員 + 2025: 給与/従業員');

        $fresh = $company->fresh();
        $this->assertSame('E00001', $fresh->edinet_code);
        $this->assertNotNull($fresh->edinet_fetched_at);
        $this->assertNull($fresh->edinet_requested_at);
    }

    public function test_取得値には有報の書類閲覧_ur_lが出典として入る(): void
    {
        $company = Company::create(['name' => 'テスト株式会社', 'listed' => true, 'legal_number' => '1234567890123']);
        $this->createDocument('S100NEW1', '2026-06-25', '2026-03-31');

        $indexer = Mockery::mock(EdinetIndexer::class)->shouldIgnoreMissing();
        $client = Mockery::mock(EdinetClient::class);
        $client->shouldReceive('documentCsvTexts')->with('S100NEW1')->andReturn([$this->sampleCsv()]);

        (new FetchEdinetJob($company->id))->handle($indexer, $client, new EdinetYuhoParser);

        $this->assertSame(
            ['https://disclosure2.edinet-fsa.go.jp/WZEK0040.aspx?S100NEW1='],
            OfficialMetric::pluck('source_url')->unique()->values()->all(),
            '全ての取得値から原文（有報）へ戻れること',
        );
    }

    public function test_決算期の無い書類は年度を確定できないため例外(): void
    {
        $company = Company::create(['name' => 'テスト株式会社', 'listed' => true, 'legal_number' => '1234567890123']);
        $this->createDocument('S100NEW1', '2026-06-25', null);

        $indexer = Mockery::mock(EdinetIndexer::class)->shouldIgnoreMissing();
        $client = Mockery::mock(EdinetClient::class)->shouldIgnoreMissing();

        try {
            (new FetchEdinetJob($company->id))->handle($indexer, $client, new EdinetYuhoParser);
            $this->fail('例外が発生するはず');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('決算期', $e->getMessage());
        }

        $this->assertSame(0, OfficialMetric::count(), '年度が決まらない値は登録しない');
        $this->assertNotNull($company->fresh()->edinet_error);
    }

    public function test_接続失敗の文言に購読キーを残さない(): void
    {
        // EDINET はキーをクエリ文字列で送るため、Guzzle の接続失敗文言に URL ごとキーが載る。
        // その文言は companies.edinet_error・画面・ログに残るので、伏せてから詰め替える
        config(['services.edinet.key' => 'secret-key-123']);
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 28: Operation timed out for https://api.edinet-fsa.go.jp/api/v2/documents.json?date=2026-01-01&type=2&Subscription-Key=secret-key-123'
        ));

        try {
            app(EdinetClient::class)->documentsFor('2026-01-01');
            $this->fail('接続失敗は例外になるはず');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('secret-key-123', $e->getMessage());
            $this->assertStringContainsString('***', $e->getMessage());
            $this->assertNull($e->getPrevious(), '伏せる前の文言を previous 経由でログに出さない');
        }
    }

    public function test_ジョブ失敗はエラーを企業に残す(): void
    {
        $company = Company::create(['name' => 'テスト株式会社', 'listed' => true]);

        $indexer = Mockery::mock(EdinetIndexer::class)->shouldIgnoreMissing();
        $client = Mockery::mock(EdinetClient::class)->shouldIgnoreMissing();

        try {
            (new FetchEdinetJob($company->id))->handle($indexer, $client, new EdinetYuhoParser);
            $this->fail('例外が発生するはず');
        } catch (\RuntimeException) {
        }

        $this->assertNotNull($company->fresh()->edinet_error);
    }

    private function createDocument(string $docId, string $docDate, ?string $periodEnd): void
    {
        EdinetDocument::create([
            'doc_date' => $docDate,
            'doc_id' => $docId,
            'edinet_code' => 'E00001',
            'jcn' => '1234567890123',
            'period_end' => $periodEnd,
            'filer_name' => 'テスト株式会社',
        ]);
    }
}
