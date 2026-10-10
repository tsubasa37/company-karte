<?php

namespace Tests\Unit;

use App\Enums\AnalysisStep;
use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Models\Company;
use App\Models\ReviewItem;
use App\Models\SourceDocument;
use App\Services\Ai\AiRunner;
use App\Services\Ai\AnalysisRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * 素材の有無によるステップのスキップ判定と、口コミ材料の件数記録を検証する。
 * codex は課金・実行時間の対象なので「呼んでも空しか返らないステップ」は呼ばせない。
 */
class AnalysisRunnerMaterialTest extends TestCase
{
    use RefreshDatabase;

    public function test_求人票が無ければ主張起点のステップは実行しない(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $runner = $this->app->make(AnalysisRunner::class);

        foreach ([AnalysisStep::P1, AnalysisStep::P2, AnalysisStep::P3, AnalysisStep::P6] as $step) {
            $this->assertFalse(
                $runner->hasMaterialFor($company, $step),
                "{$step->value} は求人票が無ければスキップされること",
            );
        }
    }

    public function test_求人票があれば主張起点のステップを実行する(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        SourceDocument::create([
            'company_id' => $company->id,
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '月給25万円〜35万円',
        ]);
        $runner = $this->app->make(AnalysisRunner::class);

        foreach ([AnalysisStep::P1, AnalysisStep::P2, AnalysisStep::P3, AnalysisStep::P6] as $step) {
            $this->assertTrue($runner->hasMaterialFor($company->fresh(), $step));
        }
    }

    public function test_p5は求人票が無くても実行する(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        $this->assertTrue(
            $this->app->make(AnalysisRunner::class)->hasMaterialFor($company, AnalysisStep::P5),
            'おすすめ度の保留判定と所見のため P5 は常に実行する',
        );
    }

    public function test_口コミが無ければp4は実行しない(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        $this->assertFalse($this->app->make(AnalysisRunner::class)->hasMaterialFor($company, AnalysisStep::P4));
    }

    public function test_スキップ理由は何が足りないかを述べる(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $runner = $this->app->make(AnalysisRunner::class);

        $this->assertSame('求人票が未投入のため、裏取りする主張がない', $runner->skipReasonFor($company, AnalysisStep::P2));
        $this->assertSame('求人票が未投入のため、逆質問の元になる主張がない', $runner->skipReasonFor($company, AnalysisStep::P6));
        $this->assertSame('口コミが未投入のため、要約する材料がない', $runner->skipReasonFor($company, AnalysisStep::P4));
    }

    public function test_素材があるステップのスキップ理由は例外にする(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        SourceDocument::create([
            'company_id' => $company->id,
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '求人票本文',
        ]);

        $this->expectException(\LogicException::class);
        $this->app->make(AnalysisRunner::class)->skipReasonFor($company->fresh(), AnalysisStep::P1);
    }

    public function test_口コミ材料の件数を取得できる(): void
    {
        $company = $this->companyWithReviews(['口コミ本文A', '口コミ本文B']);
        $runner = $this->runnerWithFakeCodex();

        $runner->runStep($company->fresh(), AnalysisStep::P4, []);

        // 並び順（ordering）も残す。P4 は機械判定の設定に関わらず新しい順
        $this->assertSame(['total' => 2, 'included' => 2, 'omitted' => 0, 'ordering' => 'recency'], $runner->reviewMaterialMeta());
    }

    public function test_文字数上限で入れなかった口コミ件数を保持する(): void
    {
        $company = $this->companyWithReviews([
            str_repeat('あ', 10000),
            str_repeat('い', 10000),
            str_repeat('う', 10000),
        ]);
        $runner = $this->runnerWithFakeCodex();

        $runner->runStep($company->fresh(), AnalysisStep::P4, []);

        $this->assertSame(['total' => 3, 'included' => 2, 'omitted' => 1, 'ordering' => 'recency'], $runner->reviewMaterialMeta());
    }

    public function test_口コミが無い場合も件数はゼロで記録される(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $runner = $this->runnerWithFakeCodex();

        $runner->runStep($company, AnalysisStep::P4, []);

        $this->assertSame(['total' => 0, 'included' => 0, 'omitted' => 0, 'ordering' => 'recency'], $runner->reviewMaterialMeta());
    }

    public function test_rjは求人票と口コミの両方がそろったときだけ実行する(): void
    {
        $runner = $this->app->make(AnalysisRunner::class);
        $reviewsOnly = $this->companyWithReviews(['口コミ本文A']);
        $jobPostingOnly = Company::create(['name' => '求人票だけ社']);
        $jobPostingOnly->sourceDocuments()->create(['type' => SourceType::JobPosting, 'origin' => SourceOrigin::Paste, 'raw_text' => '求人票本文']);

        $this->assertFalse($runner->hasMaterialFor($reviewsOnly->fresh(), AnalysisStep::RJ));
        $this->assertFalse($runner->hasMaterialFor($jobPostingOnly->fresh(), AnalysisStep::RJ));
        $this->assertSame(
            '求人票か口コミが未投入のため、主張と口コミを突き合わせる材料がない',
            $runner->skipReasonFor($jobPostingOnly->fresh(), AnalysisStep::RJ),
        );

        $reviewsOnly->sourceDocuments()->create(['type' => SourceType::JobPosting, 'origin' => SourceOrigin::Paste, 'raw_text' => '求人票本文']);
        $this->assertTrue($runner->hasMaterialFor($reviewsOnly->fresh(), AnalysisStep::RJ));
    }

    public function test_rjは生成を呼ばない(): void
    {
        // プロンプトもスキーマも持たないので、誤って runStep に渡しても生成を呼ぶ前に止まる
        $codex = Mockery::mock(AiRunner::class);
        $codex->shouldNotReceive('runWithSchema');
        $this->app->instance(AiRunner::class, $codex);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('rj はプロンプトを持たない');

        $this->app->make(AnalysisRunner::class)->runStep(Company::create(['name' => '株式会社テスト']), AnalysisStep::RJ, []);
    }

    public function test_材料整形前に件数を取ると例外になる(): void
    {
        $this->expectException(\LogicException::class);
        $this->app->make(AnalysisRunner::class)->reviewMaterialMeta();
    }

    /**
     * @param  array<int, string>  $bodies
     */
    private function companyWithReviews(array $bodies): Company
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $source = SourceDocument::create([
            'company_id' => $company->id,
            'type' => SourceType::Review,
            'origin' => SourceOrigin::Url,
            'url' => 'https://www.openwork.jp/company_answer?m_id=1',
            'raw_text' => implode("\n", $bodies),
        ]);

        foreach ($bodies as $body) {
            ReviewItem::create([
                'company_id' => $company->id,
                'source_document_id' => $source->id,
                'body' => $body,
                'body_hash' => sha1($body),
            ]);
        }

        return $company;
    }

    private function runnerWithFakeCodex(): AnalysisRunner
    {
        $codex = Mockery::mock(AiRunner::class);
        $codex->shouldReceive('runWithSchema')->andReturn(['material_summary' => '']);
        $this->app->instance(AiRunner::class, $codex);

        return $this->app->make(AnalysisRunner::class);
    }
}
