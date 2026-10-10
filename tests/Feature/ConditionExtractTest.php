<?php

namespace Tests\Feature;

use App\Actions\IngestClipAction;
use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Jobs\ExtractConditionsJob;
use App\Models\Company;
use App\Services\Ai\AiRunner;
use App\Services\Ai\ClipClassifier;
use App\Services\Ai\ConditionExtractor;
use App\Services\Ai\SourceMaterialText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ConditionExtractTest extends TestCase
{
    use RefreshDatabase;

    public function test_求人票クリップで条件抽出ジョブが投入される(): void
    {
        Queue::fake();

        $mock = Mockery::mock(ClipClassifier::class);
        $mock->shouldReceive('classify')->andReturn([
            'is_company_related' => true,
            'company_name' => '株式会社テスト',
            'source_type' => 'jobposting',
            'summary' => '求人票',
        ]);
        $this->app->instance(ClipClassifier::class, $mock);

        $this->app->make(IngestClipAction::class)
            ->execute('求人', 'https://example.com/job', '求人票テキスト');

        Queue::assertPushed(ExtractConditionsJob::class);
    }

    public function test_ジョブが労働条件を保存する(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $company->sourceDocuments()->create([
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '月給30万円（固定残業手当45時間分・6万円を含む） フルリモート可',
        ]);

        $mock = Mockery::mock(ConditionExtractor::class);
        $mock->shouldReceive('extract')->once()->andReturn([
            'salary_min' => 500,
            'salary_max' => 800,
            'salary_text' => '月給30万円（固定残業手当45時間分・6万円を含む）',
            'remote' => 'フルリモート可',
            'flex' => '',
            'overtime_pay' => '固定残業手当45時間分（6万円）を含む',
            'fixed_overtime_hours' => 45,
            'fixed_overtime_amount_yen' => 60000,
            'monthly_salary_min_yen' => 300000,
            'monthly_salary_max_yen' => 300000,
            'location' => '',
            'holidays' => '',
            'notes' => '',
        ]);

        (new ExtractConditionsJob($company->id))->handle($mock, $this->app->make(SourceMaterialText::class));

        $conditions = $company->fresh()->conditions_json;
        $this->assertSame(500, $conditions['salary_min']);
        $this->assertSame('フルリモート可', $conditions['remote']);
        // 原文表記は画面表示用に残したまま、構造化した数値も持つ
        $this->assertSame('固定残業手当45時間分（6万円）を含む', $conditions['overtime_pay']);
        $this->assertSame(45, $conditions['fixed_overtime_hours']);
        $this->assertSame(60000, $conditions['fixed_overtime_amount_yen']);
        $this->assertSame(300000, $conditions['monthly_salary_min_yen']);
        $this->assertSame(300000, $conditions['monthly_salary_max_yen']);
    }

    public function test_抽出スキーマに固定残業と月額の構造化項目が含まれる(): void
    {
        $schema = [];
        $codex = Mockery::mock(AiRunner::class);
        $codex->shouldReceive('runWithSchema')
            ->andReturnUsing(function (array $s, string $prompt) use (&$schema) {
                $schema = $s;

                return [];
            });

        (new ConditionExtractor($codex))->extract('月給30万円（固定残業45時間分・6万円を含む）');

        foreach (['fixed_overtime_hours', 'fixed_overtime_amount_yen', 'monthly_salary_min_yen', 'monthly_salary_max_yen'] as $key) {
            $this->assertContains($key, $schema['required'], "{$key} が required に含まれること");
            $this->assertSame(['integer', 'null'], $schema['properties'][$key]['type'], "{$key} は記載が無ければ null");
        }

        // 原文表記は画面で見せるため残す
        $this->assertContains('overtime_pay', $schema['required']);
    }

    public function test_抽出プロンプトは計算を指示しない(): void
    {
        $captured = '';
        $codex = Mockery::mock(AiRunner::class);
        $codex->shouldReceive('runWithSchema')
            ->andReturnUsing(function (array $s, string $prompt) use (&$captured) {
                $captured = $prompt;

                return [];
            });

        (new ConditionExtractor($codex))->extract('月給30万円');

        $this->assertStringContainsString('記載の抜き出しのみ', $captured);
        $this->assertStringContainsString('計算はしない', $captured, '基本給・実質時給の算出はPHP側の責務');
    }

    public function test_求人票が無ければ条件を消す(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $company->update(['conditions_json' => ['salary_min' => 500]]);

        $mock = Mockery::mock(ConditionExtractor::class);
        $mock->shouldNotReceive('extract');

        (new ExtractConditionsJob($company->id))->handle($mock, $this->app->make(SourceMaterialText::class));

        $this->assertNull($company->fresh()->conditions_json);
    }

    public function test_抽出失敗時は古い条件を残さない(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $company->update(['conditions_json' => ['salary_min' => 500, 'salary_max' => 800]]);

        (new ExtractConditionsJob($company->id))->failed(new \RuntimeException('codex失敗'));

        $this->assertNull(
            $company->fresh()->conditions_json,
            '古い条件が最新であるかのように比較ビューに残らないこと',
        );
    }

    public function test_求人票ソースの削除で再抽出ジョブが投入される(): void
    {
        Queue::fake();
        $company = Company::create(['name' => '株式会社テスト']);
        $source = $company->sourceDocuments()->create([
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '求人票テキスト',
        ]);

        $this->delete(route('sources.destroy', $source))->assertRedirect();

        Queue::assertPushed(ExtractConditionsJob::class);
    }
}
