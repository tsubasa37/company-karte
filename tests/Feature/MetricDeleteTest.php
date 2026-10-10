<?php

namespace Tests\Feature;

use App\Enums\MetricSource;
use App\Models\Company;
use App\Models\OfficialMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 公式数値（しょくばらぼ等の手入力・EDINET/gBizINFO の取得値）の削除 */
class MetricDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_公式数値を1件だけ削除できる(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $target = $company->officialMetrics()->create([
            'source' => MetricSource::Shokuba,
            'key' => '平均残業時間',
            'value' => '20',
            'unit' => '時間',
        ]);
        $keep = $company->officialMetrics()->create([
            'source' => MetricSource::Shokuba,
            'key' => '有給取得率',
            'value' => '60',
            'unit' => '%',
        ]);

        $this->delete(route('metrics.destroy', $target))->assertRedirect();

        $this->assertNull(OfficialMetric::find($target->id));
        $this->assertNotNull(OfficialMetric::find($keep->id), '他の数値は残る');
        $this->assertNotNull(Company::find($company->id), '企業は残る');
    }

    public function test_存在しない公式数値の削除は404(): void
    {
        $this->delete('/metrics/999999')->assertNotFound();
    }
}
