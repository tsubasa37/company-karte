<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\MetricBenchmark;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MetricBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    public function test_母集団が足りなければ理由付きで比較不能を返す(): void
    {
        $company = $this->companyWithSalary('A社', '620');
        $this->companyWithSalary('B社', '580');

        $result = $this->benchmark($company);

        $this->assertFalse($result[0]['comparable']);
        $this->assertSame(2, $result[0]['population']);
        $this->assertStringContainsString('3社', $result[0]['reason']);
    }

    public function test_中央値との差と順位を出す(): void
    {
        $company = $this->companyWithSalary('A社', '620');
        $this->companyWithSalary('B社', '580');
        $this->companyWithSalary('C社', '500');

        $result = $this->benchmark($company);

        $this->assertTrue($result[0]['comparable']);
        $this->assertSame(3, $result[0]['population']);
        $this->assertSame(1, $result[0]['rank']);
        $this->assertSame(580.0, $result[0]['median']);
        $this->assertSame(40.0, $result[0]['diff']);
        $this->assertSame(6.9, $result[0]['diffPercent']);
    }

    public function test_単位が違う数値は母集団に混ぜない(): void
    {
        $company = $this->companyWithSalary('A社', '620');
        $this->companyWithSalary('B社', '580');
        // 同じ項目名でも単位が違えば別物（万円と円を混ぜない）
        $other = Company::create(['name' => 'C社']);
        $other->officialMetrics()->create([
            'source' => 'edinet', 'key' => '平均年間給与', 'value' => '5800000', 'unit' => '円',
        ]);

        $this->assertFalse($this->benchmark($company)[0]['comparable']);
    }

    public function test_数値として読めない値は比較対象から外す(): void
    {
        $company = Company::create(['name' => 'A社']);
        $company->officialMetrics()->create([
            'source' => 'shokuba', 'key' => '平均年間給与', 'value' => '非公開', 'unit' => '万円',
        ]);

        $this->assertSame([], $this->benchmark($company));
    }

    public function test_企業ページに相対位置が渡る(): void
    {
        $company = $this->companyWithSalary('A社', '620');
        $this->companyWithSalary('B社', '580');
        $this->companyWithSalary('C社', '500');

        $this->get(route('companies.show', $company))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('benchmark.0.rank', 1)
                // JSON へ落ちる過程で 580.0 は 580 になる（画面では数値として扱えれば足りる）
                ->where('benchmark.0.median', 580)
            );
    }

    private function companyWithSalary(string $name, string $value): Company
    {
        $company = Company::create(['name' => $name]);
        $company->officialMetrics()->create([
            'source' => 'edinet',
            'key' => '平均年間給与',
            'value' => $value,
            'unit' => '万円',
        ]);

        return $company->fresh();
    }

    /** @return array<int, array<string, mixed>> */
    private function benchmark(Company $company): array
    {
        return $this->app->make(MetricBenchmark::class)->forCompany($company->fresh());
    }
}
