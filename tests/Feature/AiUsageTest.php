<?php

namespace Tests\Feature;

use App\Enums\AiProvider;
use App\Enums\AiPurpose;
use App\Models\AiUsage;
use App\Models\Company;
use App\Services\CodexRateLimitReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * AI の使用量を記録・集計する。
 * 「どの実行先に何をどれだけ使ったか」が見えないと、無駄な消費と気づかない課金に気づけないための計測。
 */
class AiUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 設定画面のテストは CodexRateLimitReader を経由する。既定のままだと
        // 開発マシンの実 ~/.codex/sessions を読んでしまい、環境依存で結果が揺れる
        config(['services.codex.home' => sys_get_temp_dir().'/karte-codex-none-'.uniqid()]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(AiProvider $provider, AiPurpose $purpose, array $attributes = []): AiUsage
    {
        return AiUsage::create([
            'provider' => $provider,
            'purpose' => $purpose,
            'duration_ms' => 1000,
            'prompt_chars' => 100,
            'output_chars' => 10,
            'succeeded' => true,
            ...$attributes,
        ]);
    }

    public function test_記録行はenumと件名ラベルに変換される(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $this->record(AiProvider::Codex, AiPurpose::Analysis, ['detail' => 'p1', 'company_id' => $company->id]);

        $usage = AiUsage::firstOrFail();
        $this->assertSame(AiProvider::Codex, $usage->provider);
        $this->assertSame(AiPurpose::Analysis, $usage->purpose);
        $this->assertSame('分析（P1〜P5）', $usage->purpose->label());
        $this->assertTrue($usage->purpose->isExplicit());
    }

    public function test_自動実行の用途は明示実行と区別される(): void
    {
        // ソース保存・定期取得に付随して走る分は「気づかない消費」なので画面で分けて出す
        $this->assertFalse(AiPurpose::ReviewSplit->isExplicit());
        $this->assertFalse(AiPurpose::ConditionExtract->isExplicit());
        $this->assertFalse(AiPurpose::MailExtract->isExplicit());
        $this->assertTrue(AiPurpose::Analysis->isExplicit());
        $this->assertTrue(AiPurpose::Questions->isExplicit());
    }

    public function test_設定画面に実行先別の集計が出る(): void
    {
        $this->record(AiProvider::Codex, AiPurpose::Analysis, [
            'model' => 'gpt-5.6-sol',
            'input_tokens' => 1000, 'cached_input_tokens' => 200, 'output_tokens' => 300, 'reasoning_tokens' => 100,
        ]);
        // codex はサブスクなのでトークンが取れない実行がありうる（費用は常に null）
        $this->record(AiProvider::Codex, AiPurpose::Analysis, ['model' => 'gpt-5.6-sol', 'succeeded' => false]);
        $this->record(AiProvider::OpenAi, AiPurpose::ReviewSplit, [
            'model' => 'gpt-test',
            'input_tokens' => 500, 'cached_input_tokens' => 0, 'output_tokens' => 100, 'cost_micro_usd' => 12345,
        ]);

        $this->get('/settings/usage')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('aiUsage.days', 30)
                ->where('aiUsage.calls', 3)
                ->where('aiUsage.autoCalls', 1)
                // 行は AiProvider::cases() の順（codex / openai / gemini / anthropic）
                ->where('aiUsage.providers.0.provider', 'codex')
                ->where('aiUsage.providers.0.isSubprocess', true)
                ->where('aiUsage.providers.0.calls', 2)
                ->where('aiUsage.providers.0.failed', 1)
                ->where('aiUsage.providers.0.inputTokens', 1000)
                ->where('aiUsage.providers.0.cachedTokens', 200)
                ->where('aiUsage.providers.0.outputTokens', 300)
                ->where('aiUsage.providers.0.reasoningTokens', 100)
                ->where('aiUsage.providers.0.costMicroUsd', null)
                ->where('aiUsage.providers.0.rateLimit.available', false)
                ->where('aiUsage.providers.1.provider', 'openai')
                ->where('aiUsage.providers.1.isSubprocess', false)
                ->where('aiUsage.providers.1.calls', 1)
                ->where('aiUsage.providers.1.autoCalls', 1)
                ->where('aiUsage.providers.1.costMicroUsd', 12345)
                // 利用枠はサブプロセス実行にしか無い。API 系で数字を作らない
                ->where('aiUsage.providers.1.rateLimit', null)
                // 未実行の実行先も行として出す（選べることが分かるように）
                ->where('aiUsage.providers.2.provider', 'gemini')
                ->where('aiUsage.providers.2.calls', 0)
                ->where('aiUsage.providers.2.inputTokens', null)
                ->where('aiUsage.providers.3.provider', 'anthropic')
                // 判定専用の実行先も使用状況には行として出す（課金される API なのでサブプロセス扱いにしない）
                ->where('aiUsage.providers.4.provider', 'jev')
                ->where('aiUsage.providers.4.isSubprocess', false)
                ->where('aiUsage.providers.4.calls', 0));
    }

    public function test_費用を出せなかった実行は理由別に数える(): void
    {
        // トークンは取れたが単価表に無い → 推定費用が実費より小さいことが分かるように数える
        $this->record(AiProvider::OpenAi, AiPurpose::Analysis, [
            'model' => 'gpt-unknown', 'input_tokens' => 100, 'output_tokens' => 10,
        ]);
        // 実行先がトークンを返さなかった
        $this->record(AiProvider::OpenAi, AiPurpose::Analysis, ['model' => 'gpt-test']);
        $this->record(AiProvider::Codex, AiPurpose::Analysis, []);

        $this->get('/settings/usage')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('aiUsage.providers.1.unpricedCalls', 1)
                ->where('aiUsage.providers.1.untokenedCalls', 1)
                // サブスク実行に費用が無いのは正常。単価未登録には数えない
                ->where('aiUsage.providers.0.unpricedCalls', 0)
                ->where('aiUsage.providers.0.untokenedCalls', 1));
    }

    public function test_片側だけトークンがある実行はトークン未取得に数える(): void
    {
        // AiPricing は input か output のどちらかが null なら費用を出せない。
        // 「両方 null のときだけトークン未取得」と数えると、片側 null の行が単価未登録に混じって誤分類になる
        $this->record(AiProvider::OpenAi, AiPurpose::Analysis, ['model' => 'gpt-test', 'input_tokens' => 100]);
        $this->record(AiProvider::OpenAi, AiPurpose::Analysis, ['model' => 'gpt-test', 'output_tokens' => 10]);

        $this->get('/settings/usage')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('aiUsage.providers.1.untokenedCalls', 2)
                ->where('aiUsage.providers.1.unpricedCalls', 0));
    }

    public function test_用途別モデル別の行にもサブスク実行かどうかが付く(): void
    {
        // 同じ「費用 null」でも、サブスク実行は「請求が無いのが正常」で API は「出せなかった」。
        // 実行先別の表と読みが食い違わないよう、内訳の行でも見分けられるようにする
        $this->record(AiProvider::Codex, AiPurpose::Analysis, ['model' => 'gpt-5.6-sol']);
        $this->record(AiProvider::OpenAi, AiPurpose::Analysis, ['model' => 'gpt-unknown', 'input_tokens' => 1, 'output_tokens' => 1]);

        $this->get('/settings/usage')
            ->assertInertia(function (AssertableInertia $page) {
                $byPurpose = collect($page->toArray()['props']['aiUsage']['byPurpose']);
                $byModel = collect($page->toArray()['props']['aiUsage']['byModel']);

                $this->assertTrue($byPurpose->firstWhere('provider', 'codex')['isSubprocess']);
                $this->assertFalse($byPurpose->firstWhere('provider', 'openai')['isSubprocess']);
                $this->assertTrue($byModel->firstWhere('provider', 'codex')['isSubprocess']);
                $this->assertFalse($byModel->firstWhere('provider', 'openai')['isSubprocess']);
            });
    }

    public function test_単価表が壊れていても使用状況は開ける(): void
    {
        // 参考換算は AiPricing を1行ずつ呼ぶ。単価表の不備で設定画面ごと 500 にせず、
        // 換算できなかった1行として数える（AiRunner::cost() と同じ扱い）
        // モデル名にドットが入るので config のドット記法では差し替えられない（表ごと入れ替える）
        config(['ai.pricing.openai' => ['gpt-5.6-sol' => ['input' => 'こわれた単価']]]);
        $this->record(AiProvider::Codex, AiPurpose::Analysis, [
            'model' => 'gpt-5.6-sol', 'input_tokens' => 100, 'output_tokens' => 10,
        ]);

        $this->get('/settings/usage')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('aiUsage.providers.0.referenceCostMicroUsd', null)
                ->where('aiUsage.providers.0.referenceUnpricedCalls', 1));
    }

    public function test_自動実行分の推定費用を分けて出す(): void
    {
        // 押していないのに走る分。抽出・判定を API にすると気づかないまま課金される
        $this->record(AiProvider::OpenAi, AiPurpose::ReviewSplit, ['model' => 'gpt-test', 'cost_micro_usd' => 1000]);
        $this->record(AiProvider::OpenAi, AiPurpose::MailExtract, ['model' => 'gpt-test', 'cost_micro_usd' => 500]);
        $this->record(AiProvider::OpenAi, AiPurpose::Analysis, ['model' => 'gpt-test', 'cost_micro_usd' => 9000]);

        $this->get('/settings/usage')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('aiUsage.autoCalls', 2)
                ->where('aiUsage.autoCostMicroUsd', 1500));
    }

    public function test_費用のある実行が無ければ合計は0ではなくnull(): void
    {
        $this->record(AiProvider::Codex, AiPurpose::ReviewSplit, []);

        $this->get('/settings/usage')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('aiUsage.autoCostMicroUsd', null)
                // サブスク実行だけなら「費用が無い」ので、出せなかった件数は 0（画面は「費用なし」と出せる）
                ->where('aiUsage.autoUnpricedCalls', 0)
                ->where('aiUsage.autoUntokenedCalls', 0)
                ->where('aiUsage.providers.0.costMicroUsd', null));
    }

    public function test_自動実行がapiで費用を出せないときは理由を件数で返す(): void
    {
        // 画面が「費用なし（＝0円）」と誤読させないための材料。単価未登録とトークン未取得を分けて数える
        $this->record(AiProvider::OpenAi, AiPurpose::ReviewSplit, [
            'model' => 'gpt-unknown', 'input_tokens' => 100, 'output_tokens' => 10,
        ]);
        $this->record(AiProvider::OpenAi, AiPurpose::MailExtract, ['model' => 'gpt-test']);
        // 明示実行（ボタン）の分は自動実行の集計に混ぜない
        $this->record(AiProvider::OpenAi, AiPurpose::Analysis, ['model' => 'gpt-unknown', 'input_tokens' => 50]);

        $this->get('/settings/usage')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('aiUsage.autoCalls', 2)
                ->where('aiUsage.autoCostMicroUsd', null)
                ->where('aiUsage.autoUnpricedCalls', 1)
                ->where('aiUsage.autoUntokenedCalls', 1));
    }

    public function test_サブスク実行に参考換算を添える(): void
    {
        // codex に請求は発生しないが、同じモデルを API で回したときの目安が無いと乗り換えの判断ができない。
        // 実費（costMicroUsd）とは別欄で「参考換算」として出す
        $this->record(AiProvider::Codex, AiPurpose::Analysis, [
            'model' => 'gpt-5.6-luna',
            'input_tokens' => 1000, 'cached_input_tokens' => 200, 'output_tokens' => 300,
        ]);
        // 単価表に無いモデルは換算できない。件数で返し「参考換算が実際より小さい」と分かるようにする
        $this->record(AiProvider::Codex, AiPurpose::Analysis, [
            'model' => 'gpt-5.4-mini', 'input_tokens' => 100, 'output_tokens' => 10,
        ]);
        // トークンが取れなかった実行は untokenedCalls で数え済みなので換算対象から外す
        $this->record(AiProvider::Codex, AiPurpose::Analysis, ['model' => 'gpt-5.6-luna']);
        $this->record(AiProvider::OpenAi, AiPurpose::Analysis, ['model' => 'gpt-test', 'cost_micro_usd' => 999]);

        $this->get('/settings/usage')
            ->assertInertia(fn (AssertableInertia $page) => $page
                // (1000-200)×0.20 + 200×0.02 + 300×1.20 = 524 マイクロUSD
                ->where('aiUsage.providers.0.referenceCostMicroUsd', 524)
                ->where('aiUsage.providers.0.referenceUnpricedCalls', 1)
                ->where('aiUsage.providers.0.untokenedCalls', 1)
                // 実費は発生しない（サブスク）
                ->where('aiUsage.providers.0.costMicroUsd', null)
                // 課金される実行先では実費が正。参考換算は持たせない
                ->where('aiUsage.providers.1.referenceCostMicroUsd', null)
                ->where('aiUsage.providers.1.referenceUnpricedCalls', null));
    }

    public function test_換算できる実行が無ければ参考換算は0ではなくnull(): void
    {
        $this->record(AiProvider::Codex, AiPurpose::Analysis, ['model' => 'gpt-5.4-mini']);

        $this->get('/settings/usage')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('aiUsage.providers.0.referenceCostMicroUsd', null)
                ->where('aiUsage.providers.0.referenceUnpricedCalls', 0));
    }

    public function test_用途別と企業別とモデル別の内訳が出る(): void
    {
        $company = Company::create(['name' => '集計対象社']);

        foreach ([AiPurpose::Analysis, AiPurpose::Analysis, AiPurpose::ReviewSplit] as $i => $purpose) {
            $this->record(AiProvider::Codex, $purpose, [
                'model' => 'gpt-5.4-mini',
                'company_id' => $company->id,
                'succeeded' => $i !== 2,
            ]);
        }
        $this->record(AiProvider::OpenAi, AiPurpose::Questions, ['model' => 'gpt-test', 'cost_micro_usd' => 7000]);

        $this->get('/settings/usage')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('aiUsage.byPurpose.0.label', '分析（P1〜P5）')
                ->where('aiUsage.byPurpose.0.provider', 'codex')
                ->where('aiUsage.byPurpose.0.providerLabel', 'codex（ChatGPTサブスク）')
                ->where('aiUsage.byPurpose.0.calls', 2)
                ->where('aiUsage.byPurpose.0.isExplicit', true)
                ->where('aiUsage.byCompany.0.name', '集計対象社')
                ->where('aiUsage.byCompany.0.calls', 3)
                // モデル別は実行の多い順。同じモデル名でも実行先が違えば別行
                ->where('aiUsage.byModel.0.model', 'gpt-5.4-mini')
                ->where('aiUsage.byModel.0.provider', 'codex')
                ->where('aiUsage.byModel.0.calls', 3)
                ->where('aiUsage.byModel.0.costMicroUsd', null)
                ->where('aiUsage.byModel.1.model', 'gpt-test')
                ->where('aiUsage.byModel.1.provider', 'openai')
                ->where('aiUsage.byModel.1.costMicroUsd', 7000));
    }

    public function test_集計期間より古い実行は含めない(): void
    {
        $usage = $this->record(AiProvider::Codex, AiPurpose::Analysis, []);
        // created_at は $fillable に無いため update() では変わらない。クエリビルダで直接書く
        AiUsage::whereKey($usage->id)->update(['created_at' => now()->subDays(31)]);

        $this->get('/settings/usage')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('aiUsage.calls', 0)
                ->where('aiUsage.providers.0.calls', 0)
                // 最終実行だけは期間で切らない（いつ最後に使ったかは残す）
                ->whereNot('aiUsage.providers.0.lastAt', null));
    }

    public function test_利用枠が読めない場合は理由を出す(): void
    {
        // 数字を作らず「読めなかった」と明示する（余裕があると誤読させない）
        config(['services.codex.home' => '/tmp/does-not-exist-'.uniqid()]);

        $result = $this->app->make(CodexRateLimitReader::class)->read();

        $this->assertFalse($result['available']);
        $this->assertNull($result['usedPercent']);
        $this->assertStringContainsString('見つかりません', $result['unavailableReason']);
    }

    public function test_セッションログから利用枠を読む(): void
    {
        $dir = sys_get_temp_dir().'/karte-codex-'.uniqid().'/sessions/2026/08/02';
        mkdir($dir, 0700, true);
        file_put_contents($dir.'/rollout-2026-08-02T11-00-00-abc.jsonl', json_encode([
            'timestamp' => '2026-08-02T02:00:00.000Z',
            'type' => 'event_msg',
            'payload' => [
                'type' => 'token_count',
                'rate_limits' => [
                    'primary' => ['used_percent' => 31.0, 'window_minutes' => 10080, 'resets_at' => 1786236767],
                    'plan_type' => 'team',
                ],
            ],
        ])."\n");
        config(['services.codex.home' => dirname($dir, 4)]);

        $result = $this->app->make(CodexRateLimitReader::class)->read();

        $this->assertTrue($result['available']);
        $this->assertSame(31.0, $result['usedPercent']);
        $this->assertSame('7日', $result['windowLabel'], '10080分は7日として表示する');
        $this->assertSame('team', $result['planType']);
    }
}
