<?php

namespace Tests\Feature;

use App\Enums\AiProvider;
use App\Models\AiProviderConfig;
use App\Models\AiSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ai_settings / ai_provider_configs の初期行。
 * 移行直後は現状の挙動（全て codex 実行）と同じ状態から始まることが要点。
 */
class AiSettingMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_実行先の初期値は両tierともcodex(): void
    {
        $setting = AiSetting::sole();

        $this->assertSame(AiProvider::Codex, $setting->analysis_provider);
        $this->assertSame(AiProvider::Codex, $setting->light_provider);
    }

    public function test_codexのプロバイダ行が作られapiキーは持たない(): void
    {
        $config = AiProviderConfig::sole();

        $this->assertSame(AiProvider::Codex, $config->provider);
        $this->assertNull($config->api_key);
        $this->assertNull($config->options);
    }

    public function test_codexのモデル指定がenvからcodex行に移り空文字はnullになる(): void
    {
        // ai_provider_configs（と、その後の ai_usages 置換）を戻し、.env 相当の値を立ててから migrate し直す
        // 段数はこの行を作る migration までの距離。後ろに migration を足したらここも増やす
        // （2026-09-23 に判定エンジンの列を足す 2 本が後ろに入ったので 8 → 10。
        // 2026-09-24 に口コミの機械判定の表と材料選抜の列の 2 本が後ろに入ったので 10 → 12。
        // 同日にニュースの機械判定の表 1 本が後ろに入ったので 12 → 13。
        // 同日に面接メモの同意スイッチの列 1 本が後ろに入ったので 13 → 14）
        Artisan::call('migrate:rollback', ['--step' => 14]);

        $this->withEnv(['CODEX_MODEL' => 'gpt-test', 'CODEX_MODEL_LIGHT' => ''], fn () => Artisan::call('migrate'));

        $config = AiProviderConfig::sole();
        $this->assertSame('gpt-test', $config->model_analysis);
        $this->assertNull($config->model_light);
    }

    public function test_configキャッシュ中はenvを読めないのでcodex行の移行を止める(): void
    {
        // 段数はこの行を作る migration までの距離。後ろに migration を足したらここも増やす
        // （2026-09-23 に判定エンジンの列を足す 2 本が後ろに入ったので 8 → 10。
        // 2026-09-24 に口コミの機械判定の表と材料選抜の列の 2 本が後ろに入ったので 10 → 12。
        // 同日にニュースの機械判定の表 1 本が後ろに入ったので 12 → 13。
        // 同日に面接メモの同意スイッチの列 1 本が後ろに入ったので 13 → 14）
        Artisan::call('migrate:rollback', ['--step' => 14]);
        // configurationIsCached() はこの束縛を返す（bootstrap/cache/config.php を実際に作らずに再現する）
        $this->app->instance('config_loaded_from_cache', true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config:clear');

        Artisan::call('migrate');
    }

    public function test_判定エンジンの列は既定でnullになりロールバックで消える(): void
    {
        // 既定は判定エンジンがオフ（初期行に値を入れない）
        $this->assertNull(AiSetting::sole()->decision_provider);
        $this->assertNull(AiProviderConfig::sole()->model_decision);

        // 判定エンジンの列を足す 2 本までを戻す。後ろに口コミの機械判定の 2 本（2026-09-24）が
        // 入ったので 2 → 4（その 2 本の列・表も一緒に消えることを確かめる）。
        // 同日にニュースの機械判定の表 1 本が後ろに入ったので 4 → 5。
        // 同日に面接メモの同意スイッチの列 1 本が後ろに入ったので 5 → 6
        Artisan::call('migrate:rollback', ['--step' => 6]);

        $this->assertFalse(Schema::hasColumn('ai_settings', 'decision_send_interview_notes'));
        $this->assertFalse(Schema::hasTable('news_screenings'));
        $this->assertFalse(Schema::hasTable('review_judgments'));
        $this->assertFalse(Schema::hasColumn('ai_settings', 'decision_review_ordering'));
        $this->assertFalse(Schema::hasColumn('ai_settings', 'decision_provider'));
        $this->assertFalse(Schema::hasColumn('ai_provider_configs', 'model_decision'));
        $this->assertTrue(Schema::hasColumn('ai_settings', 'light_provider'), '既存の列は残る');

        Artisan::call('migrate');

        $this->assertTrue(Schema::hasColumn('ai_settings', 'decision_provider'));
        $this->assertTrue(Schema::hasColumn('ai_provider_configs', 'model_decision'));
        $this->assertTrue(Schema::hasTable('review_judgments'));
        $this->assertTrue(Schema::hasTable('news_screenings'));
    }

    public function test_材料選抜のスイッチは既定でオフになりロールバックで消える(): void
    {
        // 既定は従来どおり新しい順（既存の分析の挙動を変えない）
        $this->assertFalse(AiSetting::sole()->decision_review_ordering);

        // 材料選抜の列を足す 1 本までを戻す。後ろにニュースの機械判定の表 1 本（2026-09-24）が
        // 入ったので 1 → 2（その表も一緒に消える）。
        // 同日に面接メモの同意スイッチの列 1 本が後ろに入ったので 2 → 3
        Artisan::call('migrate:rollback', ['--step' => 3]);

        $this->assertFalse(Schema::hasColumn('ai_settings', 'decision_send_interview_notes'));
        $this->assertFalse(Schema::hasTable('news_screenings'));
        $this->assertFalse(Schema::hasColumn('ai_settings', 'decision_review_ordering'));
        $this->assertTrue(Schema::hasTable('review_judgments'), '口コミの機械判定の表は残る');

        Artisan::call('migrate');

        $this->assertFalse(AiSetting::sole()->decision_review_ordering);
    }

    public function test_面接メモの同意スイッチは既定でオフになりロールバックで消える(): void
    {
        // 面接メモは最も個人的なデータなので、判定エンジンを設定済みでも既定では送らない
        $this->assertFalse(AiSetting::sole()->decision_send_interview_notes);

        // 同意スイッチの列を足す 1 本（最後の 1 本）だけを戻す
        Artisan::call('migrate:rollback', ['--step' => 1]);

        $this->assertFalse(Schema::hasColumn('ai_settings', 'decision_send_interview_notes'));
        $this->assertTrue(Schema::hasTable('news_screenings'), 'ニュースの機械判定の表は残る');

        Artisan::call('migrate');

        $this->assertFalse(AiSetting::sole()->decision_send_interview_notes);
    }

    /**
     * .env 相当の値を立てて callback を実行し、終わったら元に戻す。
     * config にはもう置いていないので、env() が読む $_ENV / $_SERVER に直接入れる。
     *
     * @param  array<string, string>  $values
     */
    private function withEnv(array $values, callable $callback): void
    {
        $previous = array_intersect_key($_SERVER, $values);
        foreach ($values as $key => $value) {
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        try {
            $callback();
        } finally {
            foreach (array_keys($values) as $key) {
                unset($_ENV[$key], $_SERVER[$key]);
            }
            foreach ($previous as $key => $value) {
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }
    }
}
