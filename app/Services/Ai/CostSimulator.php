<?php

namespace App\Services\Ai;

use App\Enums\AiKind;
use App\Enums\AiProvider;
use App\Enums\AiPurpose;
use App\Enums\AiTier;
use App\Enums\AnalysisStep;
use App\Services\Ai\Cost\SimulationInput;
use App\Services\Ai\Cost\SimulationResult;
use App\Services\Ai\Cost\SimulationRow;
use App\Services\Ai\Cost\SimulationTarget;
use App\Services\Ai\Cost\TokenRates;
use App\Services\Ai\Cost\WorkloadItem;
use App\Services\Ai\Driver\TokenUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 「API に切り替えたら1社の分析でいくらか」を、実際に API を呼ばずに見積もる。
 *
 * 手順: 1社ぶんの実行の内訳（WORKLOAD）を作る → 文字数をトークン数に換算する → AiPricing で単価を掛ける。
 * 数値の確定はすべてここで行い、画面・CLI は単位変換（マイクロ USD → USD）と並べ方だけを行う。
 *
 * 不確かさの扱い:
 * - 文字数は ai_usages に同じ用途の記録があればその平均、無ければ WORKLOAD の既定値（実測のスナップショット）
 * - トークン数は文字数からの換算。換算率が実測できなければ低め・高めの2通りを出し、1つの数字に丸めない
 * - 単価表に無いモデルは費用 null（「単価未登録」）で、0 では埋めない
 */
class CostSimulator
{
    /**
     * 1社ぶんの分析1回と、それに付随して自動で走る実行。文字数は 2026-09-03 時点の実測
     * （codex_usages・1社ぶんの分析1回とその前後の自動実行。clip_classify だけ2回の平均）。
     *
     * DB に同じ用途の記録があればそちらで上書きするので、ここの値は「記録が無い環境での土台」。
     * tier は実際の呼び出し元に合わせてある（分析 P1〜P5 = AnalysisRunner の AiTier::Analysis、
     * クリップ判定 = ClipClassifier / 口コミ分割 = ReviewSplitter / 労働条件 = ConditionExtractor の AiTier::Light）。
     *
     * 逆質問（P6）・面接前ブリーフィング・メール抽出は、1社の分析に付随して走るものではなく
     * （ボタン操作・メール取得の都合で回数が決まる）実測も無いため、この見積もりには含めない。
     */
    private const WORKLOAD = [
        ['purpose' => AiPurpose::Analysis, 'step' => AnalysisStep::P1, 'tier' => AiTier::Analysis, 'prompt' => 5436, 'output' => 14760, 'auto' => false],
        ['purpose' => AiPurpose::Analysis, 'step' => AnalysisStep::P2, 'tier' => AiTier::Analysis, 'prompt' => 15488, 'output' => 31853, 'auto' => false],
        ['purpose' => AiPurpose::Analysis, 'step' => AnalysisStep::P3, 'tier' => AiTier::Analysis, 'prompt' => 5830, 'output' => 3558, 'auto' => false],
        ['purpose' => AiPurpose::Analysis, 'step' => AnalysisStep::P4, 'tier' => AiTier::Analysis, 'prompt' => 2057, 'output' => 3765, 'auto' => false],
        ['purpose' => AiPurpose::Analysis, 'step' => AnalysisStep::P5, 'tier' => AiTier::Analysis, 'prompt' => 17244, 'output' => 3496, 'auto' => false],
        ['purpose' => AiPurpose::ReviewSplit, 'step' => null, 'tier' => AiTier::Light, 'prompt' => 3844, 'output' => 4897, 'auto' => true],
        ['purpose' => AiPurpose::ClipClassify, 'step' => null, 'tier' => AiTier::Light, 'prompt' => 4563, 'output' => 500, 'auto' => true],
        ['purpose' => AiPurpose::ConditionExtract, 'step' => null, 'tier' => AiTier::Light, 'prompt' => 5460, 'output' => 662, 'auto' => true],
    ];

    /** 実測の記録を探す表。前から順に、存在する最初の表を使う */
    /**
     * 換算率の実測に使う実行の最小文字数。これ未満は、固定で乗る分（推論・定型の指示）の
     * 比率が大きすぎて率が歪む。実際の分析は 1,000 字以上なので実運用の記録は落ちない。
     */
    private const MIN_SAMPLE_CHARS = 200;

    public function __construct(private readonly AiPricing $pricing) {}

    public function simulate(SimulationInput $input): SimulationResult
    {
        $table = $this->usageTable();
        $workload = $this->workload($input, $table);
        $rates = $this->rates($table);

        $rows = array_map(
            fn (SimulationTarget $target) => $this->row($target, $workload, $rates, $input->companies),
            $input->targets,
        );

        return new SimulationResult(
            rows: $this->sorted($rows),
            workload: $workload,
            rates: $rates,
            input: $input,
            charSourceTable: $this->anyMeasured($workload) ? $table : null,
        );
    }

    /**
     * 実測を引く表。移行前の旧表（codex_usages）も見る例外を置いていたが、
     * 移行 migration が旧表を drop するため到達しなくなったので外した。
     * 表がまだ無い（migrate 前）ときは実測を諦めて既定値で見積もる。
     */
    private function usageTable(): ?string
    {
        return Schema::hasTable('ai_usages') ? 'ai_usages' : null;
    }

    /**
     * 1社ぶんの実行の内訳。回数 0 の用途（付随実行を外した場合など）は落とす。
     *
     * @return array<int, WorkloadItem>
     */
    private function workload(SimulationInput $input, ?string $table): array
    {
        $samples = $this->charSamples($table);
        $items = [];

        foreach (self::WORKLOAD as $spec) {
            $calls = $this->callsPerCompany($spec, $input);

            if ($calls === 0) {
                continue;
            }

            $detail = $spec['step']?->value;
            $sample = $samples[$spec['purpose']->value.'|'.($detail ?? '')] ?? null;

            $items[] = new WorkloadItem(
                purpose: $spec['purpose'],
                detail: $detail,
                tier: $spec['tier'],
                callsPerCompany: $calls,
                promptChars: $sample['prompt'] ?? $spec['prompt'],
                outputChars: $sample['output'] ?? $spec['output'],
                measured: $sample !== null,
                sampleCount: $sample['count'] ?? 0,
            );
        }

        return $items;
    }

    /**
     * その用途を1社あたり何回実行するか。
     *
     * P2 は判定人数ぶん独立に実行される（AnalyzeCompanyJob::runVerdictPanel）ため人数を掛ける。
     *
     * @param  array<string, mixed>  $spec
     */
    private function callsPerCompany(array $spec, SimulationInput $input): int
    {
        if ($spec['auto'] && ! $input->includeAuto) {
            return 0;
        }

        // 用途を足したのに回数を決め忘れた場合は UnhandledMatchError で落とす（黙って 0 回にしない）
        return match (true) {
            $spec['step'] === AnalysisStep::P2 => $input->panelSize,
            $spec['purpose'] === AiPurpose::Analysis => 1,
            $spec['purpose'] === AiPurpose::ReviewSplit => $input->reviewSplits,
            $spec['purpose'] === AiPurpose::ClipClassify => $input->clipClassifies,
            $spec['purpose'] === AiPurpose::ConditionExtract => $input->conditionExtracts,
        };
    }

    /**
     * 用途 × 内訳ごとの、実際に走った実行の平均文字数。
     * 失敗した実行は出力が空で平均を押し下げるため、成功した実行だけを見る。
     *
     * @return array<string, array{prompt: int, output: int, count: int}>
     */
    private function charSamples(?string $table): array
    {
        if ($table === null) {
            return [];
        }

        return DB::table($table)
            ->where('succeeded', true)
            ->selectRaw('purpose, detail, COUNT(*) AS calls, AVG(prompt_chars) AS prompt_chars, AVG(output_chars) AS output_chars')
            ->groupBy('purpose', 'detail')
            ->get()
            ->mapWithKeys(fn (object $row) => [
                $row->purpose.'|'.($row->detail ?? '') => [
                    'prompt' => (int) round((float) $row->prompt_chars),
                    'output' => (int) round((float) $row->output_chars),
                    'count' => (int) $row->calls,
                ],
            ])
            ->all();
    }

    /**
     * 文字数 → トークン数の換算率。
     *
     * トークン数が記録された実行があれば、その比（トークン合計 ÷ 文字数合計）を実測値として使い、
     * 低め・高めを同じ値にする（幅を出す必要が無くなる）。記録が無ければ config/ai.php の既定の幅。
     * トークンの列を持つのは ai_usages だけなので、旧表しか無い環境では実測できない。
     */
    private function rates(?string $table): TokenRates
    {
        $low = (float) config('ai.estimate.tokens_per_char_low');
        $high = (float) config('ai.estimate.tokens_per_char_high');

        if ($low <= 0 || $high < $low) {
            throw new \RuntimeException('config/ai.php の estimate.tokens_per_char_low / high が不正です（0 より大きく、low <= high）');
        }

        $measured = $table === 'ai_usages' ? $this->measuredRates() : null;

        return $measured ?? new TokenRates(
            inputLow: $low,
            inputHigh: $high,
            outputLow: $low,
            outputHigh: $high,
            measured: false,
            sampleCount: 0,
        );
    }

    /**
     * 記録されたトークン数と文字数の比。実測として使える行が無ければ null。
     *
     * 接続テストは除く。`{"ok":true}` の 11 文字に対して推論トークンが数百乗ることがあり、
     * これ1件だけを実測にすると出力の率が桁違いになる（キー登録直後は実際にこの1件しか無い）。
     * 短すぎる実行も同じ理由で外す。
     */
    private function measuredRates(): ?TokenRates
    {
        $row = DB::table('ai_usages')
            ->where('succeeded', true)
            // 判定専用の実行先は出力が無料で出力トークンが 0 のため、生成の換算率を引き下げてしまう
            ->whereIn('provider', array_map(fn (AiProvider $provider) => $provider->value, AiProvider::ofKind(AiKind::Generation)))
            ->where('purpose', '!=', AiPurpose::ConnectionTest->value)
            ->whereNotNull('input_tokens')
            ->whereNotNull('output_tokens')
            ->where('prompt_chars', '>=', self::MIN_SAMPLE_CHARS)
            ->where('output_chars', '>=', self::MIN_SAMPLE_CHARS)
            ->selectRaw('COUNT(*) AS calls, SUM(input_tokens) AS input_tokens, SUM(prompt_chars) AS prompt_chars, SUM(output_tokens) AS output_tokens, SUM(output_chars) AS output_chars')
            ->first();

        if ($row === null || (int) $row->calls === 0) {
            return null;
        }

        // 出力側は思考トークンを含むぶん文字数より多く出る。入力と分けて実測することで、その差もそのまま率に入る
        $input = (float) $row->input_tokens / (float) $row->prompt_chars;
        $output = (float) $row->output_tokens / (float) $row->output_chars;

        return new TokenRates(
            inputLow: $input,
            inputHigh: $input,
            outputLow: $output,
            outputHigh: $output,
            measured: true,
            sampleCount: (int) $row->calls,
        );
    }

    /**
     * 実行先1つぶんの見積もり。トークンは実行1回ごとに換算してから回数を掛ける
     * （段階単価は1回の入力総量で帯が決まるため、合算してから引くと帯を誤る）。
     *
     * @param  array<int, WorkloadItem>  $workload
     */
    private function row(SimulationTarget $target, array $workload, TokenRates $rates, int $companies): SimulationRow
    {
        $inputLow = $inputHigh = $outputLow = $outputHigh = 0;
        $costLow = $costHigh = 0;
        $priced = true;

        foreach ($workload as $item) {
            $calls = $item->callsPerCompany;

            $tokensLow = new TokenUsage(
                input: $this->toTokens($item->promptChars, $rates->inputLow),
                output: $this->toTokens($item->outputChars, $rates->outputLow),
            );
            $tokensHigh = new TokenUsage(
                input: $this->toTokens($item->promptChars, $rates->inputHigh),
                output: $this->toTokens($item->outputChars, $rates->outputHigh),
            );

            $inputLow += $tokensLow->input * $calls;
            $inputHigh += $tokensHigh->input * $calls;
            $outputLow += $tokensLow->output * $calls;
            $outputHigh += $tokensHigh->output * $calls;

            // キャッシュは効かない前提（同じプロンプトを繰り返さないので、最初の1回の額を見積もる）
            $microLow = $this->pricing->costMicroUsd($target->provider, $target->model, $tokensLow);
            $microHigh = $this->pricing->costMicroUsd($target->provider, $target->model, $tokensHigh);

            if ($microLow === null || $microHigh === null) {
                // 単価表に無いモデル。費用は 0 で埋めず「単価未登録」として返す
                $priced = false;

                continue;
            }

            $costLow += $microLow * $calls;
            $costHigh += $microHigh * $calls;
        }

        return new SimulationRow(
            target: $target,
            inputTokensLow: $inputLow,
            inputTokensHigh: $inputHigh,
            outputTokensLow: $outputLow,
            outputTokensHigh: $outputHigh,
            perCompanyMicroUsdLow: $priced ? $costLow : null,
            perCompanyMicroUsdHigh: $priced ? $costHigh : null,
            totalMicroUsdLow: $priced ? $costLow * $companies : null,
            totalMicroUsdHigh: $priced ? $costHigh * $companies : null,
        );
    }

    private function toTokens(int $chars, float $rate): int
    {
        return (int) round($chars * $rate);
    }

    /**
     * 安い順（1社あたりの低め → 高め）。単価未登録は比べようがないので末尾にまとめる。
     *
     * @param  array<int, SimulationRow>  $rows
     * @return array<int, SimulationRow>
     */
    private function sorted(array $rows): array
    {
        usort($rows, function (SimulationRow $a, SimulationRow $b) {
            if ($a->isPriced() !== $b->isPriced()) {
                return $a->isPriced() ? -1 : 1;
            }

            return [$a->perCompanyMicroUsdLow, $a->perCompanyMicroUsdHigh, $a->target->provider->value, $a->target->model]
                <=> [$b->perCompanyMicroUsdLow, $b->perCompanyMicroUsdHigh, $b->target->provider->value, $b->target->model];
        });

        return $rows;
    }

    /** @param  array<int, WorkloadItem>  $workload */
    private function anyMeasured(array $workload): bool
    {
        foreach ($workload as $item) {
            if ($item->measured) {
                return true;
            }
        }

        return false;
    }
}
