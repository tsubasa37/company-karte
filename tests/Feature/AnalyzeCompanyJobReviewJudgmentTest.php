<?php

namespace Tests\Feature;

use App\Enums\AiProvider;
use App\Enums\AiPurpose;
use App\Enums\AnalysisStep;
use App\Enums\RunStatus;
use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Enums\StepStatus;
use App\Jobs\AnalyzeCompanyJob;
use App\Models\AiSetting;
use App\Models\AnalysisRun;
use App\Models\Company;
use App\Models\ReviewItem;
use App\Models\ReviewJudgment;
use App\Services\Ai\AiDecider;
use App\Services\Ai\AiRunner;
use App\Services\Ai\Decision\ChoiceAnswer;
use App\Services\Ai\Decision\DecisionResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * 分析ジョブの口コミの機械判定（RJ）ステップ。
 * 判定エンジン未設定なら skipped で従来どおりの材料になること、設定済みなら判定を保存し、
 * 材料選抜のスイッチがオンのときだけ P2 の口コミが関連度順になること（P4 は常に新しい順）、
 * 判定の失敗はステップとランを Failed にして理由を残すことを見る。AI は生成・判定とも Mockery で閉じる。
 */
class AnalyzeCompanyJobReviewJudgmentTest extends TestCase
{
    use RefreshDatabase;

    /** 口コミ本文（ID 順）。プロンプト内の出現順で並びを確かめる */
    private const BODIES = ['本文ALPHA', '本文BRAVO', '本文CHARLIE'];

    /** 従来の並び（新しい ID 順） */
    private const RECENCY = ['本文CHARLIE', '本文BRAVO', '本文ALPHA'];

    /** @var array<string, string> 生成の detail（p2 / p4）=> 受け取ったプロンプト */
    private array $prompts = [];

    /**
     * P1・P3・P5 を完了済みにして、RJ・P2・P4 だけを走らせるラン。
     *
     * @param  list<array<string, mixed>>  $claims
     */
    private function pendingRun(array $claims = [['claim' => '残業は月20時間以内', 'quote' => '残業月20h', 'category' => 'overtime', 'verifiable' => true, 'importance' => 'high']]): AnalysisRun
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $company->sourceDocuments()->create([
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '求人票本文',
        ]);
        $doc = $company->sourceDocuments()->create([
            'type' => SourceType::Review,
            'origin' => SourceOrigin::Paste,
            'raw_text' => implode("\n", self::BODIES),
            'split_at' => now(),
        ]);
        foreach (self::BODIES as $body) {
            ReviewItem::create([
                'company_id' => $company->id,
                'source_document_id' => $doc->id,
                'body' => $body,
                'body_hash' => sha1($body),
            ]);
        }

        $run = $company->analysisRuns()->create(['status' => RunStatus::Queued]);
        foreach (AnalysisStep::pipeline() as $step) {
            $run->steps()->create(match ($step) {
                AnalysisStep::P1 => ['step' => $step->value, 'status' => StepStatus::Done, 'output_json' => ['claims' => $claims]],
                AnalysisStep::RJ, AnalysisStep::P2, AnalysisStep::P4 => ['step' => $step->value, 'status' => StepStatus::Pending],
                default => ['step' => $step->value, 'status' => StepStatus::Done, 'output_json' => []],
            });
        }

        return $run;
    }

    /** 生成（P2・P4）を閉じ、受け取ったプロンプトを残す */
    private function fakeGeneration(): void
    {
        $ai = Mockery::mock(AiRunner::class);
        $ai->shouldReceive('runWithSchema')->andReturnUsing(function ($schema, string $prompt, $tier, $purpose, string $detail) {
            $this->prompts[$detail] = $prompt;

            return $detail === AnalysisStep::P2->value ? ['verdicts' => []] : ['material_summary' => '要約'];
        });
        $this->app->instance(AiRunner::class, $ai);
    }

    /**
     * 判定エンジンを設定済みにし、口コミごとの関連度（主張 0 の矛盾の確率）で応答する判定を差し替える。
     *
     * @param  list<float>  $contradict  口コミ（ID 順）ごとの矛盾の確率
     */
    private function fakeDecision(array $contradict, bool $ordering): MockInterface
    {
        AiSetting::sole()->update(['decision_provider' => AiProvider::Jev, 'decision_review_ordering' => $ordering]);

        $decider = Mockery::mock(AiDecider::class);
        $decider->shouldReceive('isConfigured')->andReturnTrue();
        $decider->shouldReceive('decideMany')
            ->once()
            ->with(Mockery::type('array'), AiPurpose::ReviewJudgment, 'rj', Mockery::type('int'))
            ->andReturn(array_map(fn (float $p) => new DecisionResponse(
                ['relation_0' => new ChoiceAnswer('contradict', ['contradict' => $p, 'support' => 0.0, 'none' => round(1 - $p, 2)], 0.9)],
                null,
                AiProvider::Jev,
                'jev-1.13.0',
            ), $contradict));
        $this->app->instance(AiDecider::class, $decider);

        return $decider;
    }

    private function handle(AnalysisRun $run): void
    {
        $this->app->call([new AnalyzeCompanyJob($run->id), 'handle']);
    }

    /**
     * プロンプト内の口コミ本文の出現順。
     *
     * @return list<string>
     */
    private function reviewOrder(string $prompt): array
    {
        $positions = [];
        foreach (self::BODIES as $body) {
            $position = mb_strpos($prompt, $body);
            $this->assertNotFalse($position, "{$body} がプロンプトに無い");
            $positions[$body] = $position;
        }
        asort($positions);

        return array_keys($positions);
    }

    public function test_判定エンジン未設定ならrjはスキップして口コミは従来の並びのまま(): void
    {
        // スイッチがオンでも、判定エンジンがオフなら関連度が無いので従来の並び
        AiSetting::sole()->update(['decision_review_ordering' => true]);
        $this->fakeGeneration();
        $run = $this->pendingRun();

        $this->handle($run);

        $rj = $run->stepFor(AnalysisStep::RJ);
        $this->assertSame(StepStatus::Skipped, $rj->status);
        $this->assertStringContainsString('判定エンジン未設定', $rj->output_json['reason']);
        $this->assertSame(RunStatus::Done, $run->fresh()->status);
        $this->assertSame(self::RECENCY, $this->reviewOrder($this->prompts['p2']));
        $this->assertSame(self::RECENCY, $this->reviewOrder($this->prompts['p4']));
        $this->assertSame(0, ReviewJudgment::count());
    }

    public function test_設定済みなら判定を保存し材料選抜オフならp2は従来の並び(): void
    {
        $this->fakeGeneration();
        $this->fakeDecision([0.9, 0.1, 0.5], ordering: false);
        $run = $this->pendingRun();

        $this->handle($run);

        $rj = $run->stepFor(AnalysisStep::RJ);
        $this->assertSame(StepStatus::Done, $rj->status);
        $this->assertSame(3, $rj->output_json['judged_items']);
        $this->assertSame(3, ReviewJudgment::where('analysis_run_id', $run->id)->count());
        $this->assertSame(self::RECENCY, $this->reviewOrder($this->prompts['p2']));
        $this->assertSame(self::RECENCY, $this->reviewOrder($this->prompts['p4']));
        $this->assertSame('recency', $run->stepFor(AnalysisStep::P4)->output_json['material_meta']['ordering']);
    }

    public function test_材料選抜オンならp2だけ関連度の高い順になる(): void
    {
        $this->fakeGeneration();
        // 古い ID の ALPHA が関連度 0.9 で先頭に来る
        $this->fakeDecision([0.9, 0.1, 0.5], ordering: true);
        $run = $this->pendingRun();

        $this->handle($run);

        $this->assertSame(['本文ALPHA', '本文CHARLIE', '本文BRAVO'], $this->reviewOrder($this->prompts['p2']));
        // P4 は主張に無い話題も読むので、常に新しい順
        $this->assertSame(self::RECENCY, $this->reviewOrder($this->prompts['p4']));
        $this->assertSame(RunStatus::Done, $run->fresh()->status);
    }

    public function test_続きから再開したときは保存済みの関連度でp2を並べる(): void
    {
        $this->fakeGeneration();
        AiSetting::sole()->update(['decision_provider' => AiProvider::Jev, 'decision_review_ordering' => true]);
        $decider = Mockery::mock(AiDecider::class);
        $decider->shouldReceive('isConfigured')->andReturnTrue();
        $decider->shouldNotReceive('decideMany');
        $this->app->instance(AiDecider::class, $decider);

        $run = $this->pendingRun();
        [$alpha, $bravo, $charlie] = ReviewItem::orderBy('id')->pluck('id')->all();
        // JSON を経由して保存された関連度（鍵は口コミ ID）
        $run->stepFor(AnalysisStep::RJ)->update([
            'status' => StepStatus::Done,
            'output_json' => ['judged_items' => 3, 'claims' => 1, 'dropped_claims' => 0, 'model' => 'jev-1.13.0',
                'relevance' => [$alpha => 0.2, $bravo => 0.8, $charlie => 0.5]],
        ]);

        $this->handle($run);

        $this->assertSame(['本文BRAVO', '本文CHARLIE', '本文ALPHA'], $this->reviewOrder($this->prompts['p2']));
    }

    public function test_判定の後に取り込んだ口コミは判定済みの後ろに置く(): void
    {
        $this->fakeGeneration();
        AiSetting::sole()->update(['decision_provider' => AiProvider::Jev, 'decision_review_ordering' => true]);
        $decider = Mockery::mock(AiDecider::class);
        $decider->shouldReceive('isConfigured')->andReturnTrue();
        $this->app->instance(AiDecider::class, $decider);

        $run = $this->pendingRun();
        // CHARLIE は判定の後に取り込まれた（関連度が無い）
        [$alpha, $bravo] = ReviewItem::orderBy('id')->pluck('id')->all();
        $run->stepFor(AnalysisStep::RJ)->update([
            'status' => StepStatus::Done,
            'output_json' => ['judged_items' => 2, 'claims' => 1, 'dropped_claims' => 0, 'model' => 'jev-1.13.0',
                'relevance' => [$alpha => 0.2, $bravo => 0.8]],
        ]);

        $this->handle($run);

        $this->assertSame(['本文BRAVO', '本文ALPHA', '本文CHARLIE'], $this->reviewOrder($this->prompts['p2']));
    }

    public function test_主張が0件ならrjはスキップして判定を呼ばない(): void
    {
        $this->fakeGeneration();
        AiSetting::sole()->update(['decision_provider' => AiProvider::Jev]);
        $decider = Mockery::mock(AiDecider::class);
        $decider->shouldReceive('isConfigured')->andReturnTrue();
        $decider->shouldNotReceive('decideMany');
        $this->app->instance(AiDecider::class, $decider);
        $run = $this->pendingRun(claims: []);

        $this->handle($run);

        $rj = $run->stepFor(AnalysisStep::RJ);
        $this->assertSame(StepStatus::Skipped, $rj->status);
        $this->assertSame('主張が 0 件のため、口コミと突き合わせる対象がない', $rj->output_json['reason']);
    }

    public function test_判定が失敗したらrjとランを失敗にして理由を残す(): void
    {
        $ai = Mockery::mock(AiRunner::class);
        $ai->shouldNotReceive('runWithSchema');
        $this->app->instance(AiRunner::class, $ai);
        AiSetting::sole()->update(['decision_provider' => AiProvider::Jev]);
        $decider = Mockery::mock(AiDecider::class);
        $decider->shouldReceive('isConfigured')->andReturnTrue();
        $decider->shouldReceive('decideMany')->once()->andThrow(
            new \RuntimeException('判定の一括実行で 1 / 3 件が失敗しました。最初の失敗: Jev API がエラーを返しました（HTTP 500）'),
        );
        $this->app->instance(AiDecider::class, $decider);
        $run = $this->pendingRun();

        try {
            $this->handle($run);
            $this->fail('例外が再送出されるはず（握り潰し禁止）');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('1 / 3 件が失敗', $e->getMessage());
        }

        $rj = $run->stepFor(AnalysisStep::RJ);
        $this->assertSame(StepStatus::Failed, $rj->status);
        $this->assertStringContainsString('1 / 3 件が失敗', $rj->error);
        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('1 / 3 件が失敗', $run->error);
        $this->assertSame(StepStatus::Pending, $run->stepFor(AnalysisStep::P2)->status, '後続は実行しない');
        $this->assertSame(0, ReviewJudgment::count());
    }
}
