<?php

namespace Tests\Feature;

use App\Enums\AnalysisStep;
use App\Enums\RunStatus;
use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Enums\StepStatus;
use App\Jobs\GenerateQuestionsJob;
use App\Models\Company;
use App\Services\Ai\AiRunner;
use App\Services\Ai\AnalysisRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Tests\TestCase;

/**
 * 逆質問(P6)は分析パイプラインから外し、面接が決まってから個別に生成する。
 * 面接に進まない企業で毎回 codex を消費しないための分離。
 */
class QuestionsGenerateTest extends TestCase
{
    use RefreshDatabase;

    private function analyzedCompany(): Company
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $company->sourceDocuments()->create([
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '求人票本文',
        ]);
        $run = $company->analysisRuns()->create([
            'status' => RunStatus::Done,
            'finished_at' => now(),
        ]);
        $run->steps()->create([
            'step' => AnalysisStep::P2->value,
            'status' => StepStatus::Done,
            'output_json' => ['verdicts' => [[
                'claim' => '残業月20時間',
                'source' => '求人票',
                'checked_against' => '',
                'verdict' => 'unverifiable',
                'rationale' => '材料なし',
                'source_ids' => [],
            ]]],
        ]);

        return $company;
    }

    public function test_分析には逆質問が含まれない(): void
    {
        // P6 が pipeline に入っていると、面接に進まない企業でも毎回 codex を消費する
        $this->assertNotContains(AnalysisStep::P6, AnalysisStep::pipeline());
        $this->assertContains(AnalysisStep::P6, AnalysisStep::all(), '表示・書き出しの対象からは外さない');
    }

    public function test_未生成のカルテには生成状態が渡る(): void
    {
        $company = $this->analyzedCompany();

        $this->get("/companies/{$company->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('karte.p6', null)
                ->where('karte.questions.generated', false)
                ->where('karte.questions.isGenerating', false)
                ->where('karte.questions.error', null));
    }

    public function test_ボタンで生成ジョブが投入されpendingが立つ(): void
    {
        Queue::fake();
        $company = $this->analyzedCompany();

        $this->post("/companies/{$company->id}/questions")->assertRedirect();

        Queue::assertPushed(GenerateQuestionsJob::class);
        // キュー待機中も「生成中」と表示し、連打での codex 重複実行を防ぐ
        $step = $company->analysisRuns()->firstOrFail()->fresh()->stepFor(AnalysisStep::P6);
        $this->assertSame(StepStatus::Pending, $step->status);
        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('karte.questions.isGenerating', true));
    }

    public function test_キュー待機中の連打は弾かれる(): void
    {
        Queue::fake();
        $company = $this->analyzedCompany();

        $this->post("/companies/{$company->id}/questions")->assertRedirect();
        $this->post("/companies/{$company->id}/questions")->assertSessionHasErrors('questions');

        Queue::assertPushed(GenerateQuestionsJob::class, 1);
    }

    public function test_分析の実行中は生成できない(): void
    {
        // 実行中に生成すると、新ラン完了と同時に表示対象が切り替わり生成結果が見えなくなる
        Queue::fake();
        $company = $this->analyzedCompany();
        $company->analysisRuns()->create(['status' => RunStatus::Running]);

        $this->post("/companies/{$company->id}/questions")
            ->assertSessionHasErrors('questions');

        Queue::assertNothingPushed();
    }

    public function test_p2の判定が無いランでは生成できない(): void
    {
        // 逆質問は P2 の判定を質問に変換するため、判定ゼロで codex を呼んでも空撃ちになる
        Queue::fake();
        $company = Company::create(['name' => 'P2なし社']);
        $company->analysisRuns()->create(['status' => RunStatus::Done, 'finished_at' => now()]);

        $this->post("/companies/{$company->id}/questions")
            ->assertSessionHasErrors('questions');

        Queue::assertNothingPushed();
    }

    public function test_分析未完了では生成できない(): void
    {
        Queue::fake();
        $company = Company::create(['name' => '未分析社']);

        $this->post("/companies/{$company->id}/questions")
            ->assertSessionHasErrors('questions');

        Queue::assertNothingPushed();
    }

    public function test_生成中の再要求は弾かれる(): void
    {
        Queue::fake();
        $company = $this->analyzedCompany();
        $company->analysisRuns()->firstOrFail()->steps()->create([
            'step' => AnalysisStep::P6->value,
            'status' => StepStatus::Running,
        ]);

        $this->post("/companies/{$company->id}/questions")
            ->assertSessionHasErrors('questions');

        Queue::assertNothingPushed();
    }

    public function test_ジョブが逆質問を同じランのステップに保存する(): void
    {
        $company = $this->analyzedCompany();
        $run = $company->analysisRuns()->firstOrFail();

        $codex = Mockery::mock(AiRunner::class);
        $codex->shouldReceive('runWithSchema')->once()->andReturn(['questions' => [[
            'priority' => 'high',
            'question' => '直近1年の平均残業時間を教えてください',
            'targets' => '残業月20時間',
            'ask_to' => 'hr',
            'casual_version' => '残業ってどのくらいですか',
        ]]]);
        $this->app->instance(AiRunner::class, $codex);

        (new GenerateQuestionsJob($run->id))->handle($this->app->make(AnalysisRunner::class));

        $step = $run->fresh()->stepFor(AnalysisStep::P6);
        $this->assertSame(StepStatus::Done, $step->status);
        $this->assertSame('直近1年の平均残業時間を教えてください', $step->output_json['questions'][0]['question']);
    }

    public function test_p2の判定が無いランでジョブが直接呼ばれたら失敗として明示する(): void
    {
        // コントローラが事前に弾くため通常は到達しない異常系。黙ってスキップせず例外にする
        // （現在のソース有無で判定すると、生成済み逆質問をスキップで上書きして消す事故が起きる）
        $company = Company::create(['name' => '求人票なし社']);
        $run = $company->analysisRuns()->create(['status' => RunStatus::Done, 'finished_at' => now()]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('主張検証（P2）の結果が無い');

        (new GenerateQuestionsJob($run->id))->handle($this->app->make(AnalysisRunner::class));
    }

    public function test_生成済みの逆質問がスキップ上書きで消えることはない(): void
    {
        // 分析後に求人票ソースを削除しても、対象ランの P2 判定がある限り再生成できる
        // （以前は「現在のソース有無」で判定していたため、生成済み questions がスキップで消えた）
        $company = $this->analyzedCompany();
        $run = $company->analysisRuns()->firstOrFail();
        $run->steps()->create([
            'step' => AnalysisStep::P6->value,
            'status' => StepStatus::Done,
            'output_json' => ['questions' => [[
                'priority' => 'high', 'question' => '既存の質問', 'targets' => 't', 'ask_to' => 'hr', 'casual_version' => '',
            ]]],
        ]);
        $company->sourceDocuments()->delete();

        $codex = Mockery::mock(AiRunner::class);
        $codex->shouldReceive('runWithSchema')->once()->andReturn(['questions' => []]);
        $this->app->instance(AiRunner::class, $codex);

        (new GenerateQuestionsJob($run->id))->handle($this->app->make(AnalysisRunner::class));

        $step = $run->fresh()->stepFor(AnalysisStep::P6);
        $this->assertSame(StepStatus::Done, $step->status);
        $this->assertArrayNotHasKey('skipped', $step->output_json, 'ソースを消してもスキップ上書きされない');
    }

    public function test_未生成でもmarkdownは理由つきで書き出される(): void
    {
        $company = $this->analyzedCompany();

        $body = (string) $this->get("/companies/{$company->id}/export.md")->assertOk()->getContent();

        $this->assertStringContainsString('## 面接での逆質問', $body);
        $this->assertStringContainsString('未生成', $body, '黙って落とさず理由を書く');
    }
}
