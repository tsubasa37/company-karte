<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * 料金の見積もりコマンド。API を呼ばずに単価表のモデルを並べ、
 * 「これは換算による見積もりで、実際の課金はトークン数で決まる」ことを必ず添えることを固定する。
 */
class CostEstimateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_単価表のモデルが安い順に並び正常終了する(): void
    {
        $output = $this->estimate(['--companies' => 20]);

        // 単価表（config/ai.php）にある Gemini のモデルが全部並ぶ
        foreach (array_keys(config('ai.pricing.gemini')) as $model) {
            $this->assertStringContainsString($model, $output);
        }

        $this->assertStringContainsString('20社 合計', $output);
        // 安い順: gemini-3.5-flash-lite（入力 0.30 / 出力 2.50）が gemini-3.1-pro-preview より前
        $this->assertLessThan(
            strpos($output, 'gemini-3.1-pro-preview'),
            strpos($output, 'gemini-3.5-flash-lite'),
        );
    }

    public function test_既定は円表示でレートと請求通貨を明示する(): void
    {
        config(['ai.estimate.jpy_per_usd' => 150.0]);

        $output = $this->estimate();

        $this->assertStringContainsString('1社あたり（円）', $output);
        $this->assertStringContainsString('¥', $output);
        // 円は目安で、請求は USD であることを必ず添える
        $this->assertStringContainsString('1 USD = 150.00円', $output);
        $this->assertStringContainsString('請求は各社とも USD', $output);
    }

    public function test_通貨にusdを指定するとドル表示になる(): void
    {
        $output = $this->estimate(['--currency' => 'usd']);

        $this->assertStringContainsString('1社あたり（USD）', $output);
        $this->assertStringContainsString('$', $output);
        $this->assertStringNotContainsString('¥', $output);
    }

    public function test_レートが壊れていると0円と出さずに止まる(): void
    {
        config(['ai.estimate.jpy_per_usd' => 0]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('estimate.jpy_per_usd');

        $this->estimate();
    }

    public function test_換算の見積もりであることと前提を必ず添える(): void
    {
        $output = $this->estimate();

        $this->assertStringContainsString('実際の課金はトークン数で決まる', $output);
        $this->assertStringContainsString('トークン/文字', $output);
        $this->assertStringContainsString('裏取り（P2）の判定人数', $output);
        // codex はサブスクなので費用 0、代わりに利用枠を消費する
        // 表示通貨に依存しない言い方で「サブスクなら課金されない」ことを添える
        $this->assertStringContainsString('API 課金は発生しません', $output);
        $this->assertStringContainsString('ChatGPT の利用枠', $output);
    }

    public function test_人数の既定は直近の分析実行に合わせる(): void
    {
        Company::create(['name' => '株式会社テスト'])
            ->analysisRuns()->create(['status' => RunStatus::Queued, 'panel_size' => 3]);

        $this->assertStringContainsString('判定人数: 3人', $this->estimate());
        // 明示指定はそちらが勝つ
        $this->assertStringContainsString('判定人数: 1人', $this->estimate(['--panel' => 1]));
    }

    public function test_付随実行を外すと内訳から消える(): void
    {
        $this->assertStringContainsString('口コミの分割', $this->estimate());
        $this->assertStringNotContainsString('口コミの分割', $this->estimate(['--include-auto' => 0]));
    }

    public function test_codexは費用の見積もり対象外として弾く(): void
    {
        $exit = Artisan::call('ai:cost-estimate', ['--provider' => 'codex']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('単価表を持ちません', Artisan::output());
    }

    public function test_判定専用の実行先は見積もり対象外として弾く(): void
    {
        $exit = Artisan::call('ai:cost-estimate', ['--provider' => 'jev']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('判定専用の実行先は料金シミュレーションの対象外です', Artisan::output());
    }

    public function test_全件の見積もりに判定専用のモデルは並ばない(): void
    {
        $this->assertStringNotContainsString('jev-1.13.0', $this->estimate());
    }

    public function test_不正なオプションは理由を出して失敗する(): void
    {
        $this->assertSame(1, Artisan::call('ai:cost-estimate', ['--companies' => 0]));
        $this->assertStringContainsString('--companies は1以上の整数', Artisan::output());

        $this->assertSame(1, Artisan::call('ai:cost-estimate', ['--provider' => 'unknown']));
        $this->assertStringContainsString('不明な実行先', Artisan::output());

        $this->assertSame(1, Artisan::call('ai:cost-estimate', ['--include-auto' => 'まる']));
        $this->assertStringContainsString('--include-auto は 1 か 0', Artisan::output());
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function estimate(array $options = []): string
    {
        $this->assertSame(0, Artisan::call('ai:cost-estimate', $options), 'コマンドは 0 で終了するはず');

        return Artisan::output();
    }
}
