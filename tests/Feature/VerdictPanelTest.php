<?php

namespace Tests\Feature;

use App\Actions\ExportKarteMarkdownAction;
use App\Actions\SplitReviewSourceAction;
use App\Enums\AnalysisStep;
use App\Enums\Recommendation;
use App\Enums\RunStatus;
use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Enums\StepStatus;
use App\Enums\VerdictPerspective;
use App\Jobs\AnalyzeCompanyJob;
use App\Models\AnalysisRun;
use App\Models\Company;
use App\Services\Ai\AiDecider;
use App\Services\Ai\AnalysisRunner;
use App\Services\Ai\QuoteVerifier;
use App\Services\Ai\RecommendationEvaluator;
use App\Services\Ai\ReviewJudgmentRunner;
use App\Services\Ai\VerdictConsensus;
use App\Services\MaterialFreshness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Tests\TestCase;

/**
 * P2（裏取り）を複数の判定者で実行し、PHP 側で1つに確定する経路。
 * 判定者ごとの生出力が analysis_opinions に残ること、確定結果が analysis_steps に入ること、
 * ジョブの締め切りに人数が反映されることを固定する。
 */
class VerdictPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_3人で実行すると観点ごとに判定して多数決で確定する(): void
    {
        $run = $this->runWithPanel(3);

        $runner = Mockery::mock(AnalysisRunner::class);
        $runner->shouldReceive('hasMaterialFor')->andReturn(true);
        $this->expectPanelist($runner, VerdictPerspective::Base, 'match', '1人目の根拠');
        $this->expectPanelist($runner, VerdictPerspective::Official, 'contradict', '2人目の根拠');
        $this->expectPanelist($runner, VerdictPerspective::Review, 'match', '3人目の根拠');

        $this->runJob($run, $runner);

        $verdict = $run->steps()->firstWhere('step', AnalysisStep::P2->value)->output_json['verdicts'][0];
        $this->assertSame('match', $verdict['verdict']);
        $this->assertSame(3, $verdict['consensus']['panel_size']);
        $this->assertSame(2, $verdict['consensus']['agreed']);
        $this->assertFalse($verdict['consensus']['is_unanimous']);

        // 判定者ごとの生出力（集計前）が残り、確定の根拠を後から検証できる
        $this->assertSame(3, $run->opinions()->count());
        $opinions = $run->opinions()->orderBy('panelist_index')->get();
        $this->assertSame(VerdictPerspective::Base, $opinions[0]->perspective);
        $this->assertSame(VerdictPerspective::Review, $opinions[2]->perspective);
        $this->assertSame('2人目の根拠', $opinions[1]->output_json['verdicts'][0]['rationale']);
    }

    public function test_人数1なら判定者は1人だけで従来と同じ判定になる(): void
    {
        $run = $this->runWithPanel(1);

        $runner = Mockery::mock(AnalysisRunner::class);
        $runner->shouldReceive('hasMaterialFor')->andReturn(true);
        $this->expectPanelist($runner, VerdictPerspective::Base, 'contradict', '唯一の根拠');
        // 2人目以降の観点では実行されない
        $runner->shouldNotReceive('runStep')
            ->with(Mockery::any(), AnalysisStep::P2, Mockery::any(), VerdictPerspective::Official);

        $this->runJob($run, $runner);

        $verdict = $run->steps()->firstWhere('step', AnalysisStep::P2->value)->output_json['verdicts'][0];
        $this->assertSame('contradict', $verdict['verdict']);
        $this->assertSame('唯一の根拠', $verdict['rationale']);
        $this->assertSame(1, $run->opinions()->count());
    }

    public function test_判定が割れたら判定割れとして確定する(): void
    {
        $run = $this->runWithPanel(3);

        $runner = Mockery::mock(AnalysisRunner::class);
        $runner->shouldReceive('hasMaterialFor')->andReturn(true);
        $this->expectPanelist($runner, VerdictPerspective::Base, 'match', '1人目の根拠');
        $this->expectPanelist($runner, VerdictPerspective::Official, 'contradict', '2人目の根拠');
        $this->expectPanelist($runner, VerdictPerspective::Review, 'unverifiable', '3人目の根拠');

        $this->runJob($run, $runner);

        $verdict = $run->steps()->firstWhere('step', AnalysisStep::P2->value)->output_json['verdicts'][0];
        // 材料不足（unverifiable）と混ぜず、面接で確認すべき論点として独立した値で残す
        $this->assertSame(VerdictConsensus::SPLIT, $verdict['verdict']);
        $this->assertSame('split', $verdict['consensus']['decided_by']);
    }

    public function test_割れた判定はおすすめ度の確認要因になる(): void
    {
        $evaluator = $this->app->make(RecommendationEvaluator::class);
        $p5 = ['must_checks' => [['condition' => '年収400万以上', 'status' => 'met', 'evidence' => '求人票']]];
        $p3 = ['rhetoric_flags' => [], 'concreteness' => ['level' => 'high', 'reason' => '数値が具体的']];

        $withSplit = ['verdicts' => [['verdict' => VerdictConsensus::SPLIT]]];
        $withoutSplit = ['verdicts' => [['verdict' => 'match']]];

        // 割れが無ければ必須条件を満たしている以上 High、割れがあれば確認が必要な Mid に落ちる
        $this->assertSame(Recommendation::High, $evaluator->evaluate($p5, $withoutSplit, $p3));
        $this->assertSame(Recommendation::Mid, $evaluator->evaluate($p5, $withSplit, $p3));
        $this->assertStringContainsString('判定者の意見が分かれた主張が1件', $evaluator->reasonFor($p5, $withSplit, $p3));
    }

    public function test_ジョブの締め切りに判定者の人数が反映される(): void
    {
        // クランプ（retry_after - 60）に当たらない余裕のある設定で、人数ぶんの増分だけを見る
        config(['services.codex.timeout' => 180, 'queue.connections.database.retry_after' => 3000]);

        // RJ（口コミの機械判定）は生成を呼ばず、分析 Tier の上限秒では見積もらないので除く
        // （判定エンジン未設定のこのテストでは RJ の加算は 0）
        $steps = count(AnalysisStep::pipeline()) - 1;
        $this->assertSame(180 * $steps + 120, (new AnalyzeCompanyJob($this->runWithPanel(1)->id))->timeout);
        // 3人なら P2 の増員ぶん（2回）だけ締め切りが伸びる
        $this->assertSame(180 * ($steps + 2) + 120, (new AnalyzeCompanyJob($this->runWithPanel(3)->id))->timeout);
    }

    public function test_用意した観点より多い人数は受け付けない(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        $this->post("/companies/{$company->id}/analyze", ['panel_size' => VerdictPerspective::maxPanelSize() + 1])
            ->assertSessionHasErrors('panel_size');
        $this->post("/companies/{$company->id}/analyze", ['panel_size' => 0])
            ->assertSessionHasErrors('panel_size');
    }

    /**
     * 判定割れは「材料不足」と混ぜず、注意すべき順（矛盾の次）で表に出す。
     * 集計・並び順・書き出しはどれも判定の値を見て分岐するため、経路ごとに固定する。
     */
    public function test_判定割れはカルテとエクスポートに独立して出る(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $run = $company->analysisRuns()->create([
            'status' => RunStatus::Done,
            'panel_size' => 3,
            'finished_at' => now(),
        ]);
        $run->steps()->create([
            'step' => AnalysisStep::P2->value,
            'status' => StepStatus::Done,
            'output_json' => ['verdicts' => [
                $this->savedVerdict('リモート可', 'match'),
                $this->savedVerdict('残業は月20時間以内', VerdictConsensus::SPLIT),
                $this->savedVerdict('離職率5%', 'contradict'),
            ]],
        ]);

        // 画面: 矛盾 → 判定割れ → 整合 の順。件数も材料不足と混ざらない
        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('karte.p2.verdicts.0.verdict', 'contradict')
                ->where('karte.p2.verdicts.1.verdict', VerdictConsensus::SPLIT)
                ->where('karte.p2.verdicts.2.verdict', 'match')
                ->where('karte.verdictCounts.split', 1)
                ->where('karte.verdictCounts.unverifiable', 0)
                ->etc());

        // 書き出し: 判定割れが独立した見出しになり、合議の内訳も残る
        $markdown = $this->app->make(ExportKarteMarkdownAction::class)->execute($company->fresh());
        $this->assertStringContainsString('🔀判定割れ 1', $markdown);
        $this->assertStringContainsString('### 🔀 判定割れ（1件）', $markdown);
        $this->assertStringContainsString('3人の判定が分かれた', $markdown);
        $this->assertStringContainsString('公式データ重視: ⚠️ 矛盾', $markdown);
        // 判定者ごとに見た材料が違うので、意見ごとに突合先が残ること
        $this->assertStringContainsString('突合先: しょくばらぼ #12', $markdown);
        // 何人で裏取りしたカルテかが持ち出し先でも分かること
        $this->assertStringContainsString('- 裏取り: 3人（基本 / 公式データ重視 / 口コミ重視）', $markdown);
    }

    public function test_判定割れの内訳には判定者ごとの材料が残る(): void
    {
        $consensus = $this->app->make(VerdictConsensus::class);
        $row = fn (string $verdict, string $checked, array $ids) => [
            'claim' => '残業は月20時間以内', 'claim_index' => 0, 'source' => '求人票',
            'checked_against' => $checked, 'source_ids' => $ids,
            'verdict' => $verdict, 'rationale' => '根拠',
        ];

        $merged = $consensus->merge([
            ['perspective' => VerdictPerspective::Base, 'output' => ['verdicts' => [$row('match', '求人票 #1', [1])]]],
            ['perspective' => VerdictPerspective::Official, 'output' => ['verdicts' => [$row('contradict', 'しょくばらぼ #12', [12])]]],
            ['perspective' => VerdictPerspective::Review, 'output' => ['verdicts' => [$row('unverifiable', '口コミ #34', [34])]]],
        ]);

        $opinions = $merged['verdicts'][0]['consensus']['opinions'];
        // 代表行(1人目)の材料だけでなく、各判定者が見た材料から辿れること
        $this->assertSame([1], $opinions[0]['source_ids']);
        $this->assertSame('しょくばらぼ #12', $opinions[1]['checked_against']);
        $this->assertSame([34], $opinions[2]['source_ids']);
    }

    /**
     * 必須条件の ✅ は codex がプロンプトの指示に従って出す値で、PHP 側に照合手段が無い。
     * おすすめ度は判定割れで Mid に落ちるのに個別条件は充足のまま見えるため、根拠の確認を促す。
     */
    public function test_判定割れがあるとき充足判定に根拠確認を促す(): void
    {
        $split = $this->companyWithVerdicts([
            $this->savedVerdict('残業は月20時間以内', VerdictConsensus::SPLIT),
        ]);
        $clean = $this->companyWithVerdicts([
            $this->savedVerdict('リモート可', 'match'),
        ]);

        $this->get("/companies/{$split->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('karte.checkSplitNote', '主張検証で判定が割れた項目が1件あります。'
                    .'充足判定の根拠が、判定の割れた主張に依存していないか根拠欄を確認してください。')
                ->etc());

        // 判定割れが無いカルテでは出さない（常時出すと注意書きが読み飛ばされる）
        $this->get("/companies/{$clean->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('karte.checkSplitNote', null)
                ->etc());
    }

    /**
     * 指定した P2 判定と、充足判定（P5）を持つ完了済みランを備えた企業。
     *
     * @param  array<int, array<string, mixed>>  $verdicts
     */
    private function companyWithVerdicts(array $verdicts): Company
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $run = $company->analysisRuns()->create([
            'status' => RunStatus::Done,
            'panel_size' => 3,
            'finished_at' => now(),
        ]);
        $run->steps()->create([
            'step' => AnalysisStep::P2->value,
            'status' => StepStatus::Done,
            'output_json' => ['verdicts' => $verdicts],
        ]);
        $run->steps()->create([
            'step' => AnalysisStep::P5->value,
            'status' => StepStatus::Done,
            'output_json' => ['must_checks' => [
                ['condition' => '年収400万以上', 'status' => 'met', 'evidence' => '求人票に記載'],
            ]],
        ]);

        return $company;
    }

    /**
     * 3人構成は codex 呼び出しが増える分、締め切りに引っかかって SIGKILL される可能性が高い。
     * handle の catch を経ずに落ちた場合も、実行中のステップとランが失敗として確定すること。
     */
    public function test_打ち切られたジョブもランとステップに失敗を残す(): void
    {
        $run = $this->runWithPanel(3);
        $run->steps()->where('step', AnalysisStep::P2->value)
            ->update(['status' => StepStatus::Running->value]);

        (new AnalyzeCompanyJob($run->id))->failed(new \RuntimeException('job timed out'));

        $fresh = $run->fresh();
        $this->assertSame(RunStatus::Failed, $fresh->status);
        $this->assertSame('job timed out', $fresh->error);
        $this->assertNotNull($fresh->finished_at, '終了時刻が入らないと実行中のまま見える');

        $p2 = $fresh->steps()->firstWhere('step', AnalysisStep::P2->value);
        $this->assertSame(StepStatus::Failed, $p2->status);
        $this->assertSame('job timed out', $p2->error);
        // 完了済みのステップは巻き込まない（再開時に再実行させないため）
        $this->assertSame(StepStatus::Done, $fresh->steps()->firstWhere('step', AnalysisStep::P1->value)->status);
    }

    public function test_失敗確定済みのランは後から来た例外で上書きされない(): void
    {
        $run = $this->runWithPanel(3);
        $run->update(['status' => RunStatus::Failed, 'error' => '最初の失敗理由']);

        (new AnalyzeCompanyJob($run->id))->failed(new \RuntimeException('後から来た例外'));

        $this->assertSame('最初の失敗理由', $run->fresh()->error, '原因である最初の理由を残すこと');
    }

    /**
     * 3人のうち2人目で失敗したランを再開したとき、1人目の行が二重に増えないこと
     * （analysis_opinions は run+step+人数番号で一意。再実行はその番号を上書きする）。
     */
    public function test_途中で失敗したp2を再開しても判定者の行が重複しない(): void
    {
        $run = $this->runWithPanel(3);

        $first = Mockery::mock(AnalysisRunner::class);
        $first->shouldReceive('hasMaterialFor')->andReturn(true);
        $this->expectPanelist($first, VerdictPerspective::Base, 'match', '1回目の判定');
        $first->shouldReceive('runStep')
            ->with(Mockery::any(), AnalysisStep::P2, Mockery::any(), VerdictPerspective::Official)
            ->once()
            ->andThrow(new \RuntimeException('Codex failed'));

        try {
            $this->runJob($run, $first);
            $this->fail('例外が再送出されるはず（握り潰し禁止）');
        } catch (\RuntimeException) {
        }

        // 失敗した時点までの判定者ぶんは残る
        $this->assertSame(1, $run->opinions()->count());
        $this->assertSame(RunStatus::Failed, $run->fresh()->status);

        // 「失敗したステップから再開」と同じ手順で戻す
        $run->steps()->where('status', StepStatus::Failed->value)
            ->update(['status' => StepStatus::Pending->value, 'error' => null]);
        $run->update(['status' => RunStatus::Queued, 'error' => null]);

        $second = Mockery::mock(AnalysisRunner::class);
        $second->shouldReceive('hasMaterialFor')->andReturn(true);
        $this->expectPanelist($second, VerdictPerspective::Base, 'match', '2回目の判定');
        $this->expectPanelist($second, VerdictPerspective::Official, 'match', '2回目の判定');
        $this->expectPanelist($second, VerdictPerspective::Review, 'match', '2回目の判定');

        $this->runJob($run->fresh(), $second);

        $this->assertSame(3, $run->opinions()->count(), '1人目の行は上書きされ重複しないこと');
        $this->assertSame(
            '2回目の判定',
            $run->opinions()->where('panelist_index', 1)->first()->output_json['verdicts'][0]['rationale'],
        );
        $this->assertSame(RunStatus::Done, $run->fresh()->status);
    }

    /**
     * 保存済みの P2 判定1行（VerdictConsensus が確定した後の形）。
     *
     * @return array<string, mixed>
     */
    private function savedVerdict(string $claim, string $verdict): array
    {
        return [
            'claim' => $claim,
            'claim_index' => 0,
            'source' => '求人票',
            'checked_against' => 'しょくばらぼ',
            'source_ids' => [],
            'verdict' => $verdict,
            'rationale' => '判定の根拠',
            'consensus' => [
                'panel_size' => 3,
                'voted' => 3,
                'counts' => ['match' => 1, 'contradict' => 1, 'unverifiable' => 1],
                'agreed' => null,
                'is_unanimous' => false,
                'decided_by' => 'split',
                'summary' => '3人の判定が分かれた（材料を足しても解消しないため、面接で事実を確認する論点）',
                'aggregated' => true,
                // 観点が違えば突き合わせた材料も違う（公式データ重視だけが EDINET を見ている）
                'opinions' => [
                    ['perspective' => 'base', 'label' => '基本', 'verdict' => 'match',
                        'rationale' => '記載どおり', 'checked_against' => '求人票 #1', 'source_ids' => [1]],
                    ['perspective' => 'official', 'label' => '公式データ重視', 'verdict' => 'contradict',
                        'rationale' => '平均は月35時間', 'checked_against' => 'しょくばらぼ #12', 'source_ids' => [12]],
                    ['perspective' => 'review', 'label' => '口コミ重視', 'verdict' => 'unverifiable',
                        'rationale' => '証言が拮抗', 'checked_against' => '口コミ #34', 'source_ids' => [34]],
                ],
            ],
        ];
    }

    /**
     * P2 だけを走らせるラン（他ステップは完了済みにして再実行させない）。
     */
    private function runWithPanel(int $panelSize): AnalysisRun
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $company->sourceDocuments()->create([
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '求人票本文',
        ]);

        $run = $company->analysisRuns()->create([
            'status' => RunStatus::Queued,
            'panel_size' => $panelSize,
        ]);

        foreach (AnalysisStep::pipeline() as $step) {
            $run->steps()->create($step === AnalysisStep::P2
                ? ['step' => $step->value, 'status' => StepStatus::Pending]
                : ['step' => $step->value, 'status' => StepStatus::Done, 'output_json' => []]);
        }

        return $run;
    }

    /** 指定した観点の判定者が1回だけ呼ばれ、その判定を返すことを期待する */
    private function expectPanelist(
        Mockery\MockInterface $runner,
        VerdictPerspective $perspective,
        string $verdict,
        string $rationale,
    ): void {
        $runner->shouldReceive('runStep')
            ->with(Mockery::any(), AnalysisStep::P2, Mockery::any(), $perspective)
            ->once()
            ->andReturn(['verdicts' => [[
                'claim' => '残業は月20時間以内',
                'claim_index' => 0,
                'source' => '求人票',
                'checked_against' => 'しょくばらぼ',
                'source_ids' => [],
                'verdict' => $verdict,
                'rationale' => $rationale,
            ]]]);
    }

    private function runJob(AnalysisRun $run, Mockery\MockInterface $runner): void
    {
        (new AnalyzeCompanyJob($run->id))->handle(
            $runner,
            $this->app->make(RecommendationEvaluator::class),
            $this->app->make(SplitReviewSourceAction::class),
            $this->app->make(QuoteVerifier::class),
            $this->app->make(VerdictConsensus::class),
            $this->app->make(MaterialFreshness::class),
            $this->app->make(AiDecider::class),
            $this->app->make(ReviewJudgmentRunner::class),
        );
    }
}
