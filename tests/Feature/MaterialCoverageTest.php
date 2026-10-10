<?php

namespace Tests\Feature;

use App\Enums\CompanyStatus;
use App\Enums\Recommendation;
use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Models\Company;
use App\Services\MaterialCoverage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MaterialCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_求人票が無ければ材料不足と判定する(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        $result = $this->coverage($company);

        $this->assertSame(MaterialCoverage::LEVEL_INSUFFICIENT, $result['level']);
        $this->assertSame('未投入', $this->itemFor($result, 'jobposting')['detail']);
    }

    public function test_口コミが最低件数に満たなければ不足として出す(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $this->jobPosting($company);
        $this->reviewItems($company, MaterialCoverage::MIN_REVIEW_ITEMS - 1);
        $company->officialMetrics()->create([
            'source' => 'edinet', 'key' => '平均年間給与', 'value' => '6200000',
        ]);

        $result = $this->coverage($company);

        $this->assertSame(MaterialCoverage::LEVEL_PARTIAL, $result['level']);
        $this->assertFalse($this->itemFor($result, 'review')['satisfied']);
        $this->assertStringContainsString('あと1件', $this->itemFor($result, 'review')['hint']);
    }

    public function test_全て揃えば不足なしになる(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $this->jobPosting($company);
        $this->reviewItems($company, MaterialCoverage::MIN_REVIEW_ITEMS);
        $company->officialMetrics()->create([
            'source' => 'edinet', 'key' => '平均年間給与', 'value' => '6200000',
        ]);

        $result = $this->coverage($company);

        $this->assertSame(MaterialCoverage::LEVEL_SUFFICIENT, $result['level']);
        $this->assertSame(0, $result['missingCount']);
    }

    public function test_応募前の企業には面接メモの不足を出さない(): void
    {
        $company = Company::create(['name' => '株式会社テスト', 'status' => CompanyStatus::Kininaru]);
        $this->jobPosting($company);

        $keys = array_column($this->coverage($company)['items'], 'key');

        $this->assertNotContains('interview', $keys);
    }

    public function test_面接中の企業には面接メモの不足を出す(): void
    {
        $company = Company::create(['name' => '株式会社テスト', 'status' => CompanyStatus::Interview]);
        $this->jobPosting($company);

        $keys = array_column($this->coverage($company)['items'], 'key');

        $this->assertContains('interview', $keys);
    }

    public function test_1行表示の不足は先頭2件までにして残りは件数で出す(): void
    {
        // 求人票・口コミ・公式数値の3件が不足する（面接メモは応募前なので項目に出ない）
        $company = Company::create(['name' => '株式会社テスト']);

        $line = (string) $this->app->make(MaterialCoverage::class)->summaryLine($company);

        $this->assertStringContainsString('求人票', $line);
        $this->assertStringContainsString('口コミ', $line);
        $this->assertStringContainsString('他1件', $line);
    }

    public function test_企業ページに充足度が渡る(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        $this->get(route('companies.show', $company))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('coverage.level', MaterialCoverage::LEVEL_INSUFFICIENT)
                ->has('coverage.missing')
            );
    }

    public function test_ダッシュボードの判定保留に不足材料が添えられる(): void
    {
        $company = Company::create([
            'name' => '株式会社テスト',
            'recommendation' => Recommendation::Hold,
        ]);

        $this->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('holds.items.0.id', $company->id)
                ->where('holds.items.0.missing', fn ($v) => str_contains((string) $v, '求人票'))
            );
    }

    /** @return array<string, mixed> */
    private function coverage(Company $company): array
    {
        return $this->app->make(MaterialCoverage::class)->evaluate($company->fresh());
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function itemFor(array $result, string $key): array
    {
        foreach ($result['items'] as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        $this->fail("項目 {$key} が見つかりません");
    }

    private function jobPosting(Company $company): void
    {
        $company->sourceDocuments()->create([
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '求人票テキスト',
        ]);
    }

    private function reviewItems(Company $company, int $count): void
    {
        $source = $company->sourceDocuments()->create([
            'type' => SourceType::Review,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '口コミ本文',
        ]);

        for ($i = 0; $i < $count; $i++) {
            $company->reviewItems()->create([
                'source_document_id' => $source->id,
                'body' => "口コミ{$i}",
                'body_hash' => hash('sha256', "口コミ{$i}"),
            ]);
        }
    }
}
