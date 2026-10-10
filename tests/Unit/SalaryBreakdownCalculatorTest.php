<?php

namespace Tests\Unit;

use App\Actions\SplitReviewSourceAction;
use App\Enums\AnalysisStep;
use App\Enums\RunStatus;
use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Enums\StepStatus;
use App\Jobs\AnalyzeCompanyJob;
use App\Models\Company;
use App\Services\Ai\AiDecider;
use App\Services\Ai\AnalysisRunner;
use App\Services\Ai\QuoteVerifier;
use App\Services\Ai\RecommendationEvaluator;
use App\Services\Ai\ReviewJudgmentRunner;
use App\Services\Ai\VerdictConsensus;
use App\Services\MaterialFreshness;
use App\Services\SalaryBreakdownCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 基本給・実質時給は AI に暗算させず PHP で確定計算する（このアプリの中核の方針）。
 * 記載が足りないケースは推測で埋めず「算出不能」と理由を残すことも固定する。
 * 分析パイプラインで実際に computed が保存されるところまでこのファイルで押さえる。
 */
class SalaryBreakdownCalculatorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function breakdown(array $override = []): array
    {
        return [
            'has_fixed_overtime' => true,
            'fixed_overtime_hours' => 45,
            'fixed_overtime_amount_yen' => 60000,
            'monthly_salary_min_yen' => 300000,
            'monthly_salary_max_yen' => 300000,
            'note' => '',
            ...$override,
        ];
    }

    public function test_月給と固定残業の記載から基本給と実質時給を確定する(): void
    {
        $computed = SalaryBreakdownCalculator::compute($this->breakdown());

        $this->assertSame(173, $computed['monthly_work_hours']);
        $this->assertSame(240000, $computed['base_salary_yen'], '月給30万 - 固定残業代6万');
        // 300000 ÷ (173 + 45) = 1376.14... → 四捨五入して1376
        $this->assertSame(1376, $computed['effective_hourly_yen'], '月給総額 ÷ (所定173h + 固定残業45h)');
        $this->assertNull($computed['unavailable_reason']);
    }

    public function test_固定残業の記載が無ければ月給を所定労働時間で割る(): void
    {
        $computed = SalaryBreakdownCalculator::compute($this->breakdown([
            'has_fixed_overtime' => false,
            'fixed_overtime_hours' => null,
            'fixed_overtime_amount_yen' => null,
        ]));

        $this->assertSame(300000, $computed['base_salary_yen']);
        // 300000 ÷ 173 = 1734.10... → 1734
        $this->assertSame(1734, $computed['effective_hourly_yen']);
        $this->assertNull($computed['unavailable_reason']);
    }

    public function test_月給の記載が無ければ算出不能にする(): void
    {
        $computed = SalaryBreakdownCalculator::compute($this->breakdown([
            'monthly_salary_min_yen' => null,
            'monthly_salary_max_yen' => null,
        ]));

        $this->assertNull($computed['base_salary_yen']);
        $this->assertNull($computed['effective_hourly_yen']);
        $this->assertStringContainsString('月給', (string) $computed['unavailable_reason']);
        $this->assertStringContainsString('算出不能', (string) $computed['unavailable_reason']);
    }

    public function test_固定残業の時間数が無ければ算出不能にする(): void
    {
        $computed = SalaryBreakdownCalculator::compute($this->breakdown([
            'fixed_overtime_hours' => null,
        ]));

        $this->assertNull($computed['base_salary_yen'], '推測で基本給を埋めない');
        $this->assertNull($computed['effective_hourly_yen']);
        $this->assertStringContainsString('時間数', (string) $computed['unavailable_reason']);
    }

    public function test_固定残業代の金額が無ければ算出不能にする(): void
    {
        $computed = SalaryBreakdownCalculator::compute($this->breakdown([
            'fixed_overtime_amount_yen' => null,
        ]));

        $this->assertNull($computed['base_salary_yen']);
        $this->assertStringContainsString('金額', (string) $computed['unavailable_reason']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function 計算が成立しない記載(): array
    {
        return [
            '固定残業ありで時間数0' => [['fixed_overtime_hours' => 0]],
            '固定残業ありで時間数が負' => [['fixed_overtime_hours' => -173]],
            '固定残業ありで金額0' => [['fixed_overtime_amount_yen' => 0]],
            '固定残業代が月給以上' => [['fixed_overtime_amount_yen' => 300000]],
            '月給が0' => [['monthly_salary_min_yen' => 0]],
            '月給が負' => [['monthly_salary_min_yen' => -1]],
        ];
    }

    /**
     * 分母が0以下になる記載でも例外にせず「算出不能」で返す（0除算にならないこと）。
     *
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('計算が成立しない記載')]
    public function test_不整合な記載は算出不能で返す(array $override): void
    {
        $computed = SalaryBreakdownCalculator::compute($this->breakdown($override));

        $this->assertNull($computed['base_salary_yen']);
        $this->assertNull($computed['effective_hourly_yen']);
        $this->assertNotNull($computed['unavailable_reason']);
    }

    public function test_p3出力のsalary_breakdownにcomputedを入れる(): void
    {
        $output = SalaryBreakdownCalculator::apply([
            'rhetoric_flags' => [],
            'concreteness' => ['level' => 'low', 'reason' => '数値が少ない'],
            'salary_breakdown' => $this->breakdown(),
        ]);

        $this->assertSame(240000, $output['salary_breakdown']['computed']['base_salary_yen']);
        $this->assertSame([], $output['rhetoric_flags'], '他のキーは触らない');
    }

    public function test_salary_breakdownが無い出力は例外にする(): void
    {
        $this->expectException(\RuntimeException::class);

        SalaryBreakdownCalculator::apply(['rhetoric_flags' => []]);
    }

    public function test_数値が整数でなければ例外にする(): void
    {
        $this->expectException(\RuntimeException::class);

        SalaryBreakdownCalculator::compute($this->breakdown(['monthly_salary_min_yen' => '30万']));
    }

    /**
     * P3 完了直後に確定計算が走り、引用照合（filterRhetoric）と共存すること。
     */
    public function test_分析ジョブがp3完了時に実質給与を計算して保存する(): void
    {
        $jobText = '月給30万円（固定残業手当45時間分・6万円を含む）。アットホームな職場です。';

        $company = Company::create(['name' => '株式会社テスト']);
        $company->sourceDocuments()->create([
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Paste,
            'raw_text' => $jobText,
        ]);
        $run = $company->analysisRuns()->create(['status' => RunStatus::Queued]);

        // P3 だけを走らせる（他ステップは完了済みとして再実行させない）
        foreach (AnalysisStep::pipeline() as $step) {
            if ($step !== AnalysisStep::P3) {
                $run->steps()->create(['step' => $step->value, 'status' => StepStatus::Done, 'output_json' => []]);
            }
        }

        $runner = Mockery::mock(AnalysisRunner::class);
        $runner->shouldReceive('hasMaterialFor')->andReturn(true);
        $runner->shouldReceive('jobPostingText')->andReturn($jobText);
        $runner->shouldReceive('runStep')->once()->andReturn([
            'rhetoric_flags' => [
                ['phrase' => 'アットホーム', 'quote' => 'アットホームな職場です', 'concern' => '公私の境界が曖昧'],
                ['phrase' => '未経験歓迎', 'quote' => '原文に存在しない引用テキスト', 'concern' => '大量採用'],
            ],
            'concreteness' => ['level' => 'low', 'reason' => '数値が少ない'],
            'salary_breakdown' => $this->breakdown(['note' => '固定残業45時間分を含む']),
        ]);

        (new AnalyzeCompanyJob($run->id))->handle(
            $runner,
            $this->app->make(RecommendationEvaluator::class),
            $this->app->make(SplitReviewSourceAction::class),
            $this->app->make(QuoteVerifier::class),
            $this->app->make(VerdictConsensus::class),
            $this->app->make(MaterialFreshness::class),
            $this->app->make(AiDecider::class),
            $this->app->make(ReviewJudgmentRunner::class),
        );

        $p3 = $run->steps()->firstWhere('step', AnalysisStep::P3->value)->output_json;

        $this->assertSame(240000, $p3['salary_breakdown']['computed']['base_salary_yen']);
        $this->assertSame(1376, $p3['salary_breakdown']['computed']['effective_hourly_yen']);
        $this->assertSame(173, $p3['salary_breakdown']['computed']['monthly_work_hours']);
        $this->assertNull($p3['salary_breakdown']['computed']['unavailable_reason']);
        // 引用照合の結果も壊れていないこと（作文された引用は落ちる）
        $this->assertCount(1, $p3['rhetoric_flags']);
        $this->assertSame(1, $p3['dropped_flags_count']);
    }
}
