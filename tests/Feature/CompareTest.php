<?php

namespace Tests\Feature;

use App\Enums\CompanyStatus;
use App\Enums\MetricSource;
use App\Enums\Recommendation;
use App\Enums\RunStatus;
use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Enums\StepStatus;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CompareTest extends TestCase
{
    use RefreshDatabase;

    private function analyzed(string $name): Company
    {
        $company = Company::create(['name' => $name, 'recommendation' => Recommendation::High]);
        $company->analysisRuns()->create(['status' => RunStatus::Done, 'finished_at' => now()]);

        return $company;
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function step(Company $company, string $step, array $output): void
    {
        $company->analysisRuns()->first()->steps()->create([
            'step' => $step,
            'status' => StepStatus::Done,
            'output_json' => $output,
        ]);
    }

    private function jobPosting(Company $company): void
    {
        $company->sourceDocuments()->create([
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '年収400万〜1200万',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function conditions(mixed ...$overrides): array
    {
        return array_merge([
            'salary_min' => null,
            'salary_max' => null,
            'salary_text' => '',
            'remote' => '',
            'flex' => '',
            'overtime_pay' => '',
            'location' => '',
            'holidays' => '',
            'notes' => '',
        ], ...$overrides);
    }

    public function test_既定は分析済みのみ表示し畳んだ件数を返す(): void
    {
        $this->analyzed('分析済み社');
        Company::create(['name' => '未分析A社']);
        Company::create(['name' => '未分析B社']);

        $this->get('/compare')
            ->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Compare')
                ->has('rows', 1)
                ->where('rows.0.name', '分析済み社')
                ->where('hiddenCount', 2)
                ->where('showAll', false));
    }

    public function test_all指定で未分析も含めて全件表示する(): void
    {
        $this->analyzed('分析済み社');
        Company::create(['name' => '未分析社']);

        $this->get('/compare?all=1')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rows', 2)
                ->where('hiddenCount', 0)
                ->where('showAll', true));
    }

    public function test_ids指定でその企業だけを未分析でも表示する(): void
    {
        $this->analyzed('分析済み社');
        $b = Company::create(['name' => '未分析B社']);
        $c = Company::create(['name' => '未分析C社']);

        $this->get("/compare?ids[]={$b->id}&ids[]={$c->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rows', 2)
                ->where('selectedCount', 2));
    }

    public function test_ids非配列指定でも全社表示にせずその1社に絞る(): void
    {
        $a = $this->analyzed('選択した社');
        $this->analyzed('選択していない社');

        $this->get("/compare?ids={$a->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rows', 1)
                ->where('rows.0.name', '選択した社')
                ->where('selectedCount', 1));
    }

    public function test_存在しないidは空行を作らずselected_countは実取得社数(): void
    {
        $a = $this->analyzed('実在社');

        $this->get("/compare?ids[]={$a->id}&ids[]=99999")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rows', 1)
                ->where('rows.0.name', '実在社')
                ->where('selectedCount', 1));
    }

    public function test_p5のng_checksから判定件数を集計する(): void
    {
        $company = $this->analyzed('NGあり社');
        $this->step($company, 'p5', ['ng_checks' => [
            ['condition' => '多重下請け', 'status' => 'hit', 'evidence' => '口コミに記載'],
            ['condition' => '夜間対応', 'status' => 'clear', 'evidence' => '記載なし'],
            ['condition' => '転勤あり', 'status' => 'unknown', 'evidence' => '材料なし'],
        ]]);

        $this->get('/compare')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows.0.ngHit', 1)
                ->where('rows.0.ngUnknown', 1)
                ->where('rows.0.ngTotal', 3));
    }

    public function test_ng_checks導入前のランはtotal0で表示できる(): void
    {
        // 2026-07 導入以前のランには ng_checks キー自体が無い
        $company = $this->analyzed('旧ラン社');
        $this->step($company, 'p5', ['must_checks' => [['item' => 'リモート可', 'status' => 'met', 'evidence' => '']]]);

        $this->get('/compare')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows.0.mustTotal', 1)
                ->where('rows.0.ngHit', 0)
                ->where('rows.0.ngUnknown', 0)
                ->where('rows.0.ngTotal', 0));
    }

    public function test_現年収未設定はnullで渡り画面が理由を出せる(): void
    {
        $this->analyzed('分析済み社');

        $this->get('/compare')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('currentSalary', null));
    }

    public function test_p5の判定理由と懸念と分析日が行に載る(): void
    {
        $company = $this->analyzed('意思決定材料あり社');
        $this->step($company, 'p5', [
            'recommendation_reason' => '必須条件を全て満たし矛盾もありません。',
            'concern' => '固定残業45時間の記載があります。',
        ]);

        $this->get('/compare')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows.0.recommendationReason', '必須条件を全て満たし矛盾もありません。')
                ->where('rows.0.concern', '固定残業45時間の記載があります。')
                ->where('rows.0.analyzedAt', now()->format('Y-m-d'))
                // updatedAt は描画されないデッドpropだったため analyzedAt に置き換えた
                ->missing('rows.0.updatedAt'));
    }

    public function test_判定理由と懸念のキーが無い旧ランはnullで渡す(): void
    {
        // 空文字（記載なし）と区別できるよう、キーが無い場合は null にする
        $company = $this->analyzed('旧ラン社');
        $this->step($company, 'p5', ['must_checks' => []]);

        $this->get('/compare')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows.0.recommendationReason', null)
                ->where('rows.0.concern', null));
    }

    public function test_矛盾と検証不能をそれぞれ数える(): void
    {
        $company = $this->analyzed('判定あり社');
        $this->step($company, 'p2', ['verdicts' => [
            ['claim' => 'A', 'verdict' => 'contradict'],
            ['claim' => 'B', 'verdict' => 'unverifiable'],
            ['claim' => 'C', 'verdict' => 'unverifiable'],
            ['claim' => 'D', 'verdict' => 'match'],
        ]]);

        $this->get('/compare')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows.0.contradicts', 1)
                ->where('rows.0.unverifiables', 2));
    }

    public function test_公式データの平均年間給与は最新年度を載せる(): void
    {
        $company = $this->analyzed('上場社');
        foreach ([['2024', '600'], ['2025', '630']] as [$year, $value]) {
            $company->officialMetrics()->create([
                'source' => MetricSource::Edinet,
                'key' => '平均年間給与',
                'value' => $value,
                'unit' => '万円',
                'fiscal_year' => $year,
            ]);
        }

        $this->get('/compare')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows.0.avgSalary.value', '630')
                ->where('rows.0.avgSalary.unit', '万円')
                ->where('rows.0.avgSalary.fiscalYear', '2025'));
    }

    public function test_平均年間給与が無ければavg_salaryはnull(): void
    {
        $this->analyzed('未取得社');

        $this->get('/compare')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rows.0.avgSalary', null));
    }

    public function test_平均年収の先読みで社数に比例したクエリを出さない(): void
    {
        foreach (['A社', 'B社', 'C社'] as $name) {
            $company = $this->analyzed($name);
            $company->officialMetrics()->create([
                'source' => MetricSource::Edinet,
                'key' => '平均年間給与',
                'value' => '600',
                'unit' => '万円',
                'fiscal_year' => '2025',
            ]);
        }

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });

        $this->get('/compare')->assertStatus(200);

        $metricQueries = array_filter($queries, fn (string $sql) => str_contains($sql, 'official_metrics'));
        $this->assertCount(1, $metricQueries, '平均年収は with() で一括先読みする（社ごとに引かない）');
    }

    public function test_条件未抽出は求人票の有無で理由を描き分けられる(): void
    {
        // 求人票なし → 「求人票未投入」
        $none = $this->analyzed('求人票なし社');
        // 求人票あり・条件 null → 「未抽出」（抽出待ちまたは抽出失敗）
        $pending = $this->analyzed('抽出前社');
        $this->jobPosting($pending);
        // 求人票あり・条件は抽出済みだが記載が空 → 「記載なし」
        $extracted = $this->analyzed('記載なし社');
        $this->jobPosting($extracted);
        $extracted->update(['conditions_json' => $this->conditions()]);

        $this->get('/compare')
            ->assertInertia(function (AssertableInertia $page) use ($none, $pending, $extracted) {
                $rows = collect($page->toArray()['props']['rows'])->keyBy('id');

                $this->assertNull($rows[$none->id]['conditions']);
                $this->assertFalse($rows[$none->id]['hasConditionSource']);

                $this->assertNull($rows[$pending->id]['conditions']);
                $this->assertTrue($rows[$pending->id]['hasConditionSource']);

                $this->assertSame('', $rows[$extracted->id]['conditions']['location']);
                $this->assertTrue($rows[$extracted->id]['hasConditionSource']);
            });
    }

    public function test_年収レンジが広い企業には注意喚起の注記が載る(): void
    {
        $company = $this->analyzed('広レンジ社');
        $company->update(['conditions_json' => $this->conditions(['salary_min' => 400, 'salary_max' => 1200])]);

        $this->get('/compare')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows.0.salaryRange.isWide', true)
                ->where('rows.0.salaryRange.note', 'レンジ幅3.0倍（800万差）— 下限採用の可能性を確認'));
    }

    public function test_年収レンジが狭い企業には注記を出さない(): void
    {
        $company = $this->analyzed('通常レンジ社');
        $company->update(['conditions_json' => $this->conditions(['salary_min' => 500, 'salary_max' => 800])]);

        $this->get('/compare')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows.0.salaryRange.isWide', false)
                ->where('rows.0.salaryRange.note', null));
    }

    public function test_条件未抽出なら年収レンジのシグナルはnull(): void
    {
        $this->analyzed('条件未抽出社');

        $this->get('/compare')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rows.0.salaryRange', null));
    }

    public function test_ステータスで絞り込める(): void
    {
        $declined = $this->analyzed('見送り社');
        $declined->update(['status' => CompanyStatus::Declined]);
        $this->analyzed('気になる社');

        $this->get('/compare?status=kininaru')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rows', 1)
                ->where('rows.0.name', '気になる社')
                ->where('status', 'kininaru')
                ->has('statusOptions', count(CompanyStatus::cases())));
    }

    public function test_絞り込み未指定ならstatusはnullで全ステータスを返す(): void
    {
        $declined = $this->analyzed('見送り社');
        $declined->update(['status' => CompanyStatus::Declined]);
        $this->analyzed('気になる社');

        $this->get('/compare')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rows', 2)
                ->where('status', null));
    }

    public function test_未知のステータスは黙って全件表示せずエラーにする(): void
    {
        $this->analyzed('分析済み社');

        $this->get('/compare?status=unknown')->assertSessionHasErrors('status');
    }
}
