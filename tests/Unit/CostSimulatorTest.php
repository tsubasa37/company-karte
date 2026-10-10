<?php

namespace Tests\Unit;

use App\Enums\AiProvider;
use App\Enums\AiPurpose;
use App\Enums\AnalysisStep;
use App\Models\AiUsage;
use App\Services\Ai\AiPricing;
use App\Services\Ai\Cost\SimulationInput;
use App\Services\Ai\Cost\SimulationResult;
use App\Services\Ai\Cost\SimulationTarget;
use App\Services\Ai\Cost\WorkloadItem;
use App\Services\Ai\CostSimulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 料金の見積もり。「推測を確定値のように出さない」ことが要点。
 * 単価未登録は 0 ではなく null、換算率が実測できないときは1つの数字に丸めず低め・高めの2通りを出す。
 */
class CostSimulatorTest extends TestCase
{
    use RefreshDatabase;

    /** 分析 P1〜P5 の既定文字数（CostSimulator の WORKLOAD）の合計 */
    private const ANALYSIS_PROMPT_CHARS = 5436 + 15488 + 5830 + 2057 + 17244;

    private const ANALYSIS_OUTPUT_CHARS = 14760 + 31853 + 3558 + 3765 + 3496;

    /** P2 の既定文字数（判定人数ぶん増える分） */
    private const P2_PROMPT_CHARS = 15488;

    private const P2_OUTPUT_CHARS = 31853;

    protected function setUp(): void
    {
        parent::setUp();

        // 期待値を手計算できるよう、単価は入力 1.00 / 出力 2.00 USD per 1M tokens の平らな表に固定する
        config()->set('ai.pricing.gemini', ['gemini-test' => ['input' => 1.00, 'cached_input' => 0.10, 'output' => 2.00]]);
    }

    public function test_1社あたりの費用は用途ごとのトークン換算に単価を掛けた合計になる(): void
    {
        $this->fixRate(1.0);

        $result = $this->simulate(companies: 3, includeAuto: false);
        $row = $result->rows[0];

        // 換算率 1.0 なので、トークン数は文字数と同じ
        $this->assertSame(self::ANALYSIS_PROMPT_CHARS, $row->inputTokensLow);
        $this->assertSame(self::ANALYSIS_OUTPUT_CHARS, $row->outputTokensLow);

        // 単価は USD / 1M tokens なので「トークン数 × 単価」がそのままマイクロ USD
        $expected = self::ANALYSIS_PROMPT_CHARS * 1 + self::ANALYSIS_OUTPUT_CHARS * 2;
        $this->assertSame($expected, $row->perCompanyMicroUsdLow);
        $this->assertSame($expected, $row->perCompanyMicroUsdHigh);
        $this->assertSame($expected * 3, $row->totalMicroUsdLow);
    }

    public function test_単価未登録のモデルは0ではなく単価未登録として返る(): void
    {
        $this->fixRate(1.0);

        $result = $this->simulate(targets: [new SimulationTarget(AiProvider::Gemini, 'gemini-未登録')]);
        $row = $result->rows[0];

        $this->assertFalse($row->isPriced());
        $this->assertNull($row->perCompanyMicroUsdLow);
        $this->assertNull($row->perCompanyMicroUsdHigh);
        $this->assertNull($row->totalMicroUsdLow);
        $this->assertNull($row->totalMicroUsdHigh);
        // 費用が出せなくてもトークン数は換算できるので、規模は分かる
        $this->assertGreaterThan(0, $row->inputTokensLow);
    }

    public function test_裏取りの人数ぶんp2が実行され費用に効く(): void
    {
        $this->fixRate(1.0);

        $one = $this->simulate(panelSize: 1, includeAuto: false)->rows[0];
        $three = $this->simulate(panelSize: 3, includeAuto: false)->rows[0];

        $extra = 2 * (self::P2_PROMPT_CHARS * 1 + self::P2_OUTPUT_CHARS * 2);
        $this->assertSame($one->perCompanyMicroUsdLow + $extra, $three->perCompanyMicroUsdLow);
        $this->assertSame($one->inputTokensLow + 2 * self::P2_PROMPT_CHARS, $three->inputTokensLow);
    }

    public function test_実測が無いときは低めと高めの2通りを出す(): void
    {
        // 換算率は config/ai.php の既定の幅（実測なし）のまま
        $result = $this->simulate();
        $row = $result->rows[0];

        $this->assertFalse($result->rates->measured);
        $this->assertSame(0, $result->rates->sampleCount);
        $this->assertLessThan($row->inputTokensHigh, $row->inputTokensLow);
        $this->assertLessThan($row->perCompanyMicroUsdHigh, $row->perCompanyMicroUsdLow);
        $this->assertLessThan($row->totalMicroUsdHigh, $row->totalMicroUsdLow);
    }

    public function test_トークンの記録があれば換算率を実測して幅を出さない(): void
    {
        // 見積もりに含まれない用途で記録しておき、文字数の実測とは独立に換算率だけを実測させる
        $this->recordUsage(AiPurpose::Briefing, null, 400, 200, ['input_tokens' => 200, 'output_tokens' => 400]);

        $result = $this->simulate();
        $row = $result->rows[0];

        $this->assertTrue($result->rates->measured);
        $this->assertSame(1, $result->rates->sampleCount);
        $this->assertSame(0.5, $result->rates->inputLow);
        $this->assertSame(2.0, $result->rates->outputLow);
        $this->assertSame($row->perCompanyMicroUsdLow, $row->perCompanyMicroUsdHigh);
    }

    public function test_疎通確認と極小の記録は換算率の実測に使わない(): void
    {
        // 疎通確認は数トークンの固定文で、実際の分析とトークン/文字の比が全く違う
        $this->recordUsage(AiPurpose::ConnectionTest, null, 4000, 2000, ['input_tokens' => 20, 'output_tokens' => 5]);
        // 短すぎる記録は 1 トークンの端数が比を大きく振らす
        $this->recordUsage(AiPurpose::Briefing, null, 10, 10, ['input_tokens' => 30, 'output_tokens' => 30]);

        $result = $this->simulate();

        $this->assertFalse($result->rates->measured);
        $this->assertSame(0, $result->rates->sampleCount);
    }

    public function test_判定エンジンの記録は換算率の実測に使わない(): void
    {
        // 判定は出力が無料で出力トークンが 0。混ぜると生成の出力換算率が 0 に近づき費用が少なく出る
        $this->recordUsage(AiPurpose::ReviewJudgment, 'rj', 4000, 2000, ['provider' => AiProvider::Jev, 'input_tokens' => 2000, 'output_tokens' => 0]);

        $result = $this->simulate();

        $this->assertFalse($result->rates->measured);
        $this->assertSame(0, $result->rates->sampleCount);
    }

    public function test_同じ用途の記録があれば文字数は実測の平均を使う(): void
    {
        $this->recordUsage(AiPurpose::Analysis, AnalysisStep::P1->value, 1000, 2000);
        $this->recordUsage(AiPurpose::Analysis, AnalysisStep::P1->value, 3000, 4000);
        // 失敗した実行は出力が空で平均を押し下げるため、平均に混ぜない
        $this->recordUsage(AiPurpose::Analysis, AnalysisStep::P1->value, 900000, 0, ['succeeded' => false]);

        $result = $this->simulate();
        $p1 = $this->itemFor($result, AnalysisStep::P1->value);

        $this->assertTrue($p1->measured);
        $this->assertSame(2, $p1->sampleCount);
        $this->assertSame(2000, $p1->promptChars);
        $this->assertSame(3000, $p1->outputChars);
        $this->assertSame('ai_usages', $result->charSourceTable);

        // 記録の無い用途は既定値のまま（どちらを使ったかが用途ごとに分かる）
        $p2 = $this->itemFor($result, AnalysisStep::P2->value);
        $this->assertFalse($p2->measured);
        $this->assertSame(self::P2_PROMPT_CHARS, $p2->promptChars);
    }

    public function test_記録が無ければ既定値だと分かる形で返る(): void
    {
        $result = $this->simulate();

        $this->assertNull($result->charSourceTable);
        foreach ($result->workload as $item) {
            $this->assertFalse($item->measured, $item->label().' は既定値のはず');
            $this->assertSame('既定値', $item->charSourceLabel());
        }
    }

    public function test_付随実行を外すと分析5回だけになる(): void
    {
        $withAuto = $this->simulate();
        $withoutAuto = $this->simulate(includeAuto: false);

        // 分析5回 + 口コミ分割1回 + クリップ判定2回 + 労働条件1回
        $this->assertCount(8, $withAuto->workload);
        $this->assertSame(9, $withAuto->callsPerCompany());

        $this->assertCount(5, $withoutAuto->workload);
        $this->assertSame(5, $withoutAuto->callsPerCompany());
        $this->assertLessThan($withAuto->rows[0]->perCompanyMicroUsdLow, $withoutAuto->rows[0]->perCompanyMicroUsdLow);
    }

    public function test_安い順に並び単価未登録は末尾になる(): void
    {
        config()->set('ai.pricing.gemini', [
            'gemini-test' => ['input' => 1.00, 'cached_input' => 0.10, 'output' => 2.00],
            'gemini-高い' => ['input' => 10.00, 'cached_input' => 1.00, 'output' => 20.00],
        ]);

        $result = $this->simulate(targets: [
            new SimulationTarget(AiProvider::Gemini, 'gemini-未登録'),
            new SimulationTarget(AiProvider::Gemini, 'gemini-高い'),
            new SimulationTarget(AiProvider::Gemini, 'gemini-test'),
        ]);

        $this->assertSame(
            ['gemini-test', 'gemini-高い', 'gemini-未登録'],
            array_map(fn ($row) => $row->target->model, $result->rows),
        );
    }

    public function test_codexは費用の見積もり対象にできない(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('サブスク実行なので費用の見積もり対象になりません');

        new SimulationTarget(AiProvider::Codex, 'gpt-5.6-terra');
    }

    public function test_企業数や人数が0以下なら受け付けない(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('companies は 1 以上で指定してください');

        new SimulationInput(targets: [new SimulationTarget(AiProvider::Gemini, 'gemini-test')], companies: 0);
    }

    /** 換算率を1つの値に固定する（低め・高めの幅を消して期待値を手計算できるようにする） */
    private function fixRate(float $rate): void
    {
        config()->set('ai.estimate.tokens_per_char_low', $rate);
        config()->set('ai.estimate.tokens_per_char_high', $rate);
    }

    /**
     * @param  array<int, SimulationTarget>|null  $targets
     */
    private function simulate(
        ?array $targets = null,
        int $companies = 1,
        int $panelSize = 1,
        bool $includeAuto = true,
    ): SimulationResult {
        return (new CostSimulator(new AiPricing))->simulate(new SimulationInput(
            targets: $targets ?? [new SimulationTarget(AiProvider::Gemini, 'gemini-test')],
            companies: $companies,
            panelSize: $panelSize,
            includeAuto: $includeAuto,
        ));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function recordUsage(AiPurpose $purpose, ?string $detail, int $promptChars, int $outputChars, array $overrides = []): void
    {
        AiUsage::create([
            'provider' => AiProvider::Gemini,
            'purpose' => $purpose,
            'model' => 'gemini-test',
            'detail' => $detail,
            'duration_ms' => 1000,
            'prompt_chars' => $promptChars,
            'output_chars' => $outputChars,
            'succeeded' => true,
            ...$overrides,
        ]);
    }

    private function itemFor(SimulationResult $result, string $detail): WorkloadItem
    {
        foreach ($result->workload as $item) {
            if ($item->detail === $detail) {
                return $item;
            }
        }

        $this->fail("内訳 {$detail} が見積もりに含まれていません");
    }
}
