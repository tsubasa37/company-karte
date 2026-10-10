<?php

namespace App\Console\Commands;

use App\Enums\AiKind;
use App\Enums\AiProvider;
use App\Models\AnalysisRun;
use App\Services\Ai\AiPricing;
use App\Services\Ai\Cost\SimulationInput;
use App\Services\Ai\Cost\SimulationResult;
use App\Services\Ai\Cost\SimulationRow;
use App\Services\Ai\Cost\SimulationTarget;
use App\Services\Ai\Cost\WorkloadItem;
use App\Services\Ai\CostSimulator;
use Illuminate\Console\Command;

/**
 * 「API に切り替えたら1社の分析でいくらか」を、実際に API を呼ばずに見積もる。
 *
 * 対象は単価表（config/ai.php の pricing）に載っている全モデル。実行はしないので課金もサブスク枠の消費も無い。
 * 数値の確定は CostSimulator が行い、ここは並べ方と単位変換（マイクロ USD → USD）だけを担う。
 */
class EstimateAiCost extends Command
{
    protected $signature = 'ai:cost-estimate
        {--companies=1 : 合計を出す企業数}
        {--panel= : 裏取り（P2）の判定人数。既定は直近の分析実行と同じ人数（実行が無ければ1人）}
        {--provider= : 実行先で絞り込む（openai / gemini / anthropic）}
        {--include-auto=1 : 付随して自動で走る実行（口コミ分割・クリップ判定・労働条件の抽出）を含めるか（1 / 0）}
        {--currency=jpy : 費用の表示通貨（jpy / usd）。請求は USD で、円はレート換算の目安}';

    protected $description = 'AI 実行の料金を見積もる（API は呼ばない。単価表のモデルごとに1社あたり・指定社数の費用を出す）';

    public function handle(CostSimulator $simulator): int
    {
        $companies = $this->intOption('companies');
        $panel = $this->option('panel') === null ? $this->defaultPanelSize() : $this->intOption('panel');
        $includeAuto = $this->includeAutoOption();

        if ($companies === null || $panel === null || $includeAuto === null) {
            return self::FAILURE;
        }

        $targets = $this->targets();

        if ($targets === null) {
            return self::FAILURE;
        }

        $result = $simulator->simulate(new SimulationInput(
            targets: $targets,
            companies: $companies,
            panelSize: $panel,
            includeAuto: $includeAuto,
        ));

        $this->render($result);

        return self::SUCCESS;
    }

    private function render(SimulationResult $result): void
    {
        $companies = $result->input->companies;

        $this->newLine();
        $this->info('AI 料金の見積もり（実際には API を呼んでいません）');
        $this->line('1社 = 分析1回'.($result->input->includeAuto ? ' + 付随して自動で走る実行' : '（付随実行は除外）'));
        $this->newLine();

        $this->table(
            ['プロバイダ', 'モデル', '入力トークン/社', '出力トークン/社', '1社あたり'.$this->currencySuffix(), "{$companies}社 合計".$this->currencySuffix()],
            array_map(fn (SimulationRow $row) => [
                $row->target->provider->label(),
                $row->target->model,
                $this->range($row->inputTokensLow, $row->inputTokensHigh, fn (int $n) => number_format($n)),
                $this->range($row->outputTokensLow, $row->outputTokensHigh, fn (int $n) => number_format($n)),
                $this->money($row->perCompanyMicroUsdLow, $row->perCompanyMicroUsdHigh),
                $this->money($row->totalMicroUsdLow, $row->totalMicroUsdHigh),
            ], $result->rows),
        );

        $this->assumptions($result);
        $this->workload($result);

        $this->newLine();
        $this->line(sprintf(
            'codex（ChatGPTサブスク）を選んでいる間は API 課金は発生しません。代わりに ChatGPT の利用枠を1社あたり %d 回ぶん消費します（残量は設定画面の「AI の使用状況」で確認できます）。',
            $result->callsPerCompany(),
        ));
    }

    private function assumptions(SimulationResult $result): void
    {
        $rates = $result->rates;

        $this->newLine();
        $this->line('<comment>前提</comment>');

        if ($this->inJpy()) {
            $this->line(sprintf(
                '  ・通貨: 円で表示（1 USD = %s円で換算）。請求は各社とも USD で行われるため、円は目安です。レートは config/ai.php（AI_ESTIMATE_JPY_PER_USD）で変えられます。ドルで見るには --currency=usd',
                number_format($this->jpyPerUsd(), 2),
            ));
        }

        $this->line($rates->measured
            ? sprintf(
                '  ・換算率: 実測 %d件から（入力 %.2f / 出力 %.2f トークン/文字）',
                $rates->sampleCount,
                $rates->inputLow,
                $rates->outputLow,
            )
            : sprintf(
                '  ・換算率: %.2f〜%.2f トークン/文字（config/ai.php の既定の幅。実測なし）',
                $rates->inputLow,
                $rates->inputHigh,
            ));

        $this->line('  ・文字数の出所: '.($result->charSourceTable === null
            ? '実測なし（すべて既定値）'
            : "{$result->charSourceTable} の実測（用途ごとの内訳は下表）"));

        $this->line("  ・裏取り（P2）の判定人数: {$result->input->panelSize}人（この人数ぶん P2 が実行されます）");
        $this->line('  ・1社あたりの AI 実行回数: '.$result->callsPerCompany().'回');
        $this->line('  ・キャッシュは効かない前提（同じプロンプトを繰り返さないため）');
        $this->line('  ・逆質問（P6）・面接前ブリーフィング・メール抽出は、1社の分析に付随しないため含めていません');
        $this->newLine();
        $this->warn('  この見積もりは文字数からトークン数に換算したものです。実際の課金はトークン数で決まるため、額は前後します。');
    }

    private function workload(SimulationResult $result): void
    {
        $this->newLine();
        $this->line('<comment>含めた用途（1社あたり）</comment>');

        $this->table(
            ['用途', '設定の区分', '回数/社', 'プロンプト文字', '出力文字', '文字数の出所'],
            array_map(fn (WorkloadItem $item) => [
                $item->label(),
                $item->tier->label(),
                $item->callsPerCompany,
                number_format($item->promptChars),
                number_format($item->outputChars),
                $item->charSourceLabel(),
            ], $result->workload),
        );
    }

    /**
     * 見積もる実行先。単価表に載っている（= 切り替え先として比較できる）モデルを全部並べる。
     *
     * @return array<int, SimulationTarget>|null 指定が不正なら null
     */
    private function targets(): ?array
    {
        $only = $this->option('provider');

        if ($only !== null && AiProvider::tryFrom($only) === null) {
            $this->error("--provider に不明な実行先が指定されました: {$only}");

            return null;
        }

        if ($only !== null && AiProvider::from($only)->isSubprocess()) {
            $this->error(AiProvider::from($only)->label().' はサブスクで走るため単価表を持ちません（費用は常に 0 ドルです）。');

            return null;
        }

        if ($only !== null && AiProvider::from($only)->kind() !== AiKind::Generation) {
            $this->error('判定専用の実行先は料金シミュレーションの対象外です');

            return null;
        }

        $targets = [];

        foreach ((array) config('ai.pricing') as $provider => $models) {
            $enum = AiProvider::tryFrom((string) $provider);

            if ($enum === null) {
                throw new \RuntimeException("config/ai.php の pricing に不明な実行先があります: {$provider}");
            }

            if ($enum->isSubprocess() || ($only !== null && $enum->value !== $only)) {
                continue;
            }

            // 判定専用は生成の作業量で見積もれない
            if ($enum->kind() !== AiKind::Generation) {
                continue;
            }

            foreach (array_keys((array) $models) as $model) {
                $targets[] = new SimulationTarget($enum, (string) $model);
            }
        }

        if ($targets === []) {
            $this->error('単価表（config/ai.php の pricing）に対象のモデルがありません。');

            return null;
        }

        return $targets;
    }

    /** 付随実行を含めるか。値なしで --include-auto と書いた場合は「含める」の指定として扱う */
    private function includeAutoOption(): ?bool
    {
        $value = $this->option('include-auto');

        if ($value === null || $value === '') {
            return true;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($parsed === null) {
            $this->error("--include-auto は 1 か 0 で指定してください（指定値: {$value}）");

            return null;
        }

        return $parsed;
    }

    /** 直近の分析実行と同じ人数を既定にする（一度も実行していなければ1人） */
    private function defaultPanelSize(): int
    {
        return (int) (AnalysisRun::latest('id')->value('panel_size') ?? 1);
    }

    /** 数値オプションの検証。不正なら理由を出して null（例外の握り潰しではなく入力の弾き） */
    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        if (! is_numeric($value) || (int) $value != $value || (int) $value < 1) {
            $this->error("--{$name} は1以上の整数で指定してください（指定値: {$value}）");

            return null;
        }

        return (int) $value;
    }

    /**
     * 低め〜高めの2通り。換算率が実測で幅が無い場合は1つの値だけを出す。
     *
     * @param  callable(int): string  $format
     */
    private function range(int $low, int $high, callable $format): string
    {
        return $low === $high ? $format($low) : $format($low).' 〜 '.$format($high);
    }

    /** マイクロ USD を表示通貨に。単価未登録は 0 円（0 ドル）と読ませないため文言で出す */
    private function money(?int $low, ?int $high): string
    {
        if ($low === null || $high === null) {
            return '単価未登録';
        }

        if ($this->inJpy()) {
            $rate = $this->jpyPerUsd();

            // 円は目安なので小数は出さない（1円未満は「1円未満」と書き、0円と読ませない）
            return $this->range($low, $high, function (int $micro) use ($rate) {
                $yen = $micro / 1_000_000 * $rate;

                return $yen > 0 && $yen < 1 ? '1円未満' : '¥'.number_format($yen);
            });
        }

        return $this->range($low, $high, fn (int $micro) => '$'.number_format($micro / 1_000_000, 4));
    }

    /** 表の見出しに付ける通貨の別（どちらで読む表かを列名で分かるようにする） */
    private function currencySuffix(): string
    {
        return $this->inJpy() ? '（円）' : '（USD）';
    }

    /** 表示通貨が円か（既定）。usd を指定したときだけドル表示にする */
    private function inJpy(): bool
    {
        return strtolower((string) $this->option('currency')) !== 'usd';
    }

    /** レートの検証込みで AiPricing が持つ（画面側と同じ1本を使う） */
    private function jpyPerUsd(): float
    {
        return app(AiPricing::class)->jpyPerUsd();
    }
}
