<?php

namespace Tests\Feature;

use App\Enums\AiProvider;
use App\Enums\AiPurpose;
use App\Models\AiUsage;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * codex_usages → ai_usages の置き換えマイグレーション。
 * 既存の計測行が id を保ったまま provider='codex' で新表に入ることが要点。
 */
class AiUsageMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_既存のcodex_usages行がidを保ってai_usagesに移る(): void
    {
        // 置き換え migration（最後の1本）だけを戻し、旧表がある状態を作る
        // 段数はこの表を作る migration までの距離。後ろに migration を足したらここも増やす
        // （2026-09-23 に判定エンジンの列を足す 2 本が後ろに入ったので 7 → 9。
        // 2026-09-24 に口コミの機械判定の表と材料選抜の列の 2 本が後ろに入ったので 9 → 11。
        // 同日にニュースの機械判定の表 1 本が後ろに入ったので 11 → 12。
        // 同日に面接メモの同意スイッチの列 1 本が後ろに入ったので 12 → 13）
        Artisan::call('migrate:rollback', ['--step' => 13]);
        $this->assertTrue(Schema::hasTable('codex_usages'));
        $this->assertFalse(Schema::hasTable('ai_usages'));

        $company = Company::create(['name' => '移行テスト社']);
        DB::table('codex_usages')->insert([
            'id' => 42,
            'purpose' => 'analysis',
            'model' => 'gpt-5.6-sol',
            'detail' => 'p1',
            'company_id' => $company->id,
            'duration_ms' => 12000,
            'prompt_chars' => 5000,
            'output_chars' => 800,
            'succeeded' => true,
            'error' => null,
            'created_at' => '2026-08-01 10:00:00',
            'updated_at' => '2026-08-01 10:00:00',
        ]);

        Artisan::call('migrate');

        $this->assertFalse(Schema::hasTable('codex_usages'));
        $usage = AiUsage::findOrFail(42);
        $this->assertSame(AiProvider::Codex, $usage->provider);
        $this->assertSame(AiPurpose::Analysis, $usage->purpose);
        $this->assertSame('gpt-5.6-sol', $usage->model);
        $this->assertSame('p1', $usage->detail);
        $this->assertSame($company->id, $usage->company_id);
        $this->assertSame(12000, $usage->duration_ms);
        $this->assertTrue($usage->succeeded);
        $this->assertSame('2026-08-01 10:00:00', $usage->created_at->format('Y-m-d H:i:s'));
        // 旧表に無い列は「取れなかった」の null で入る（0 で埋めない）
        $this->assertNull($usage->input_tokens);
        $this->assertNull($usage->cost_micro_usd);
        $this->assertNull($usage->usage_raw);
    }

    public function test_ロールバックで旧列だけがcodex_usagesに戻る(): void
    {
        AiUsage::create([
            'provider' => AiProvider::Codex,
            'purpose' => AiPurpose::ReviewSplit,
            'duration_ms' => 100,
            'prompt_chars' => 10,
            'output_chars' => 5,
            'input_tokens' => 1234,
            'cost_micro_usd' => 50,
            'succeeded' => true,
        ]);

        // 段数はこの表を作る migration までの距離。後ろに migration を足したらここも増やす
        // （2026-09-23 に判定エンジンの列を足す 2 本が後ろに入ったので 7 → 9。
        // 2026-09-24 に口コミの機械判定の表と材料選抜の列の 2 本が後ろに入ったので 9 → 11。
        // 同日にニュースの機械判定の表 1 本が後ろに入ったので 11 → 12。
        // 同日に面接メモの同意スイッチの列 1 本が後ろに入ったので 12 → 13）
        Artisan::call('migrate:rollback', ['--step' => 13]);

        $this->assertFalse(Schema::hasTable('ai_usages'));
        $row = DB::table('codex_usages')->sole();
        $this->assertSame('review_split', $row->purpose);
        $this->assertSame(100, (int) $row->duration_ms);
        // トークン・費用・provider 列は旧表に無いので捨てられる
        $this->assertFalse(Schema::hasColumn('codex_usages', 'provider'));
        $this->assertFalse(Schema::hasColumn('codex_usages', 'input_tokens'));
    }
}
