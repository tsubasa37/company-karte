<?php

namespace Tests\Feature;

use App\Actions\MatchOpenIssuesAction;
use App\Enums\AnalysisStep;
use App\Enums\IssueStatus;
use App\Enums\RunStatus;
use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Enums\StepStatus;
use App\Jobs\MatchOpenIssuesJob;
use App\Models\AnalysisRun;
use App\Models\Company;
use App\Models\OpenIssue;
use App\Models\SourceDocument;
use App\Services\Ai\IssueResolutionMatcher;
use App\Services\Ai\SourceMaterialText;
use App\Services\Ai\VerdictConsensus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Tests\TestCase;

class OpenIssueTest extends TestCase
{
    use RefreshDatabase;

    public function test_p2の判定を論点として登録できる(): void
    {
        [$company, $run] = $this->companyWithVerdicts();

        $this->post(route('issues.store', $company), ['run_id' => $run->id, 'verdict_index' => 0])
            ->assertRedirect();

        $issue = OpenIssue::sole();
        $this->assertSame('残業は月20時間程度', $issue->claim);
        $this->assertSame('contradict', $issue->verdict);
        $this->assertSame(IssueStatus::Open, $issue->status);
        $this->assertSame('口コミでは月45時間の記述がある', $issue->rationale);
    }

    public function test_整合と判定された主張は論点にできない(): void
    {
        [$company, $run] = $this->companyWithVerdicts();

        // index=2 は verdict=match
        $this->expectException(\RuntimeException::class);
        $this->withoutExceptionHandling()
            ->post(route('issues.store', $company), ['run_id' => $run->id, 'verdict_index' => 2]);
    }

    public function test_同じ論点は二重登録されない(): void
    {
        [$company, $run] = $this->companyWithVerdicts();

        $this->post(route('issues.store', $company), ['run_id' => $run->id, 'verdict_index' => 0]);
        $this->post(route('issues.store', $company), ['run_id' => $run->id, 'verdict_index' => 0]);

        $this->assertSame(1, OpenIssue::count());
    }

    public function test_他社のランからは登録できない(): void
    {
        [, $run] = $this->companyWithVerdicts();
        $other = Company::create(['name' => '別の会社']);

        $this->expectException(\InvalidArgumentException::class);
        $this->withoutExceptionHandling()
            ->post(route('issues.store', $other), ['run_id' => $run->id, 'verdict_index' => 0]);
    }

    public function test_面接メモの投入で消し込みジョブが投入される(): void
    {
        Queue::fake();
        $company = Company::create(['name' => '株式会社テスト']);

        $this->post(route('interviews.store', $company), [
            'interviewed_on' => '2026-09-01',
            'round' => '1次',
            'answers' => '残業は月20時間程度との回答',
        ])->assertRedirect();

        Queue::assertPushed(MatchOpenIssuesJob::class);
    }

    public function test_追跡中の論点が無ければ_a_iを呼ばない(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $note = $this->interviewNote($company);

        $matcher = Mockery::mock(IssueResolutionMatcher::class);
        $matcher->shouldNotReceive('match');

        $updated = (new MatchOpenIssuesAction($matcher, $this->app->make(SourceMaterialText::class)))->execute($company, $note);

        $this->assertSame(0, $updated);
    }

    public function test_面接メモとの照合で論点の状態が更新される(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $note = $this->interviewNote($company);
        $resolved = $this->issue($company, '残業は月20時間程度');
        $untouched = $this->issue($company, '離職率は業界平均以下');

        $matcher = Mockery::mock(IssueResolutionMatcher::class);
        $matcher->shouldReceive('match')->once()->andReturn([
            ['issue_index' => 0, 'outcome' => 'resolved', 'quote' => '直近1年は月18時間', 'note' => '実績の数字が示された'],
            ['issue_index' => 1, 'outcome' => 'not_mentioned', 'quote' => '', 'note' => ''],
        ]);

        $updated = (new MatchOpenIssuesAction($matcher, $this->app->make(SourceMaterialText::class)))->execute($company, $note);

        $this->assertSame(1, $updated);
        $resolved->refresh();
        $this->assertSame(IssueStatus::Resolved, $resolved->status);
        $this->assertSame($note->id, $resolved->resolved_source_document_id);
        $this->assertStringContainsString('直近1年は月18時間', (string) $resolved->resolution_note);

        // 面接で触れられなかった論点は状態を変えず、照合した事実だけ残す
        $untouched->refresh();
        $this->assertSame(IssueStatus::Open, $untouched->status);
        $this->assertNotNull($untouched->matched_at);
    }

    public function test_範囲外の添字が返ったら例外にする(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $note = $this->interviewNote($company);
        $this->issue($company, '残業は月20時間程度');

        $matcher = Mockery::mock(IssueResolutionMatcher::class);
        $matcher->shouldReceive('match')->once()->andReturn([
            ['issue_index' => 5, 'outcome' => 'resolved', 'quote' => '', 'note' => ''],
        ]);

        $this->expectException(\RuntimeException::class);
        (new MatchOpenIssuesAction($matcher, $this->app->make(SourceMaterialText::class)))->execute($company, $note);
    }

    public function test_状態を手動で変更できる(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $issue = $this->issue($company, '残業は月20時間程度');

        $this->patch(route('issues.update', $issue), [
            'status' => IssueStatus::Dismissed->value,
            'resolution_note' => '確認の必要がなくなった',
        ])->assertRedirect();

        $issue->refresh();
        $this->assertSame(IssueStatus::Dismissed, $issue->status);
        $this->assertSame('確認の必要がなくなった', $issue->resolution_note);
    }

    public function test_状態だけ変えても面接メモ由来の根拠と出典は消えない(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $note = $this->interviewNote($company);
        $issue = $this->issue($company, '残業は月20時間程度', IssueStatus::Resolved);
        $issue->update([
            'resolution_note' => '直近1年は月18時間との回答',
            'resolved_source_document_id' => $note->id,
        ]);

        // 画面のプルダウンは status しか送らない。照合結果に納得できず戻すだけで根拠が消えてはいけない
        $this->patch(route('issues.update', $issue), ['status' => IssueStatus::Unresolved->value])
            ->assertRedirect();

        $issue->refresh();
        $this->assertSame(IssueStatus::Unresolved, $issue->status);
        $this->assertSame('直近1年は月18時間との回答', $issue->resolution_note, '根拠メモは残る');
        $this->assertSame($note->id, $issue->resolved_source_document_id, '出典リンクも残る');
    }

    public function test_手入力のメモを送ると根拠を置き換え出典リンクを外す(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $note = $this->interviewNote($company);
        $issue = $this->issue($company, '残業は月20時間程度', IssueStatus::Resolved);
        $issue->update([
            'resolution_note' => '直近1年は月18時間との回答',
            'resolved_source_document_id' => $note->id,
        ]);

        $this->patch(route('issues.update', $issue), [
            'status' => IssueStatus::Dismissed->value,
            'resolution_note' => '別ルートで確認済み',
        ])->assertRedirect();

        $issue->refresh();
        $this->assertSame('別ルートで確認済み', $issue->resolution_note);
        $this->assertNull($issue->resolved_source_document_id, '手入力のメモは面接メモ由来ではない');
    }

    public function test_論点の記録を削除できる(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $issue = $this->issue($company, '残業は月20時間程度');

        $this->delete(route('issues.destroy', $issue))->assertRedirect();

        $this->assertNull(OpenIssue::find($issue->id));
    }

    public function test_並べ替え後も元の添字で論点を登録できる(): void
    {
        [$company, $run] = $this->companyWithVerdicts();

        // 表示順は 矛盾 → 判定割れ → 整合 なので、判定割れ（保存上は 1 番目）は表示上 1 番目のまま。
        // 保存上 2 番目の match は表示上 2 番目に落ちるため、sourceIndex を経由しないと取り違える
        $verdicts = $this->get(route('companies.show', $company))
            ->viewData('page')['props']['karte']['p2']['verdicts'];

        $this->post(route('issues.store', $company), [
            'run_id' => $run->id,
            'verdict_index' => $verdicts[1]['sourceIndex'],
        ]);

        $this->assertSame('離職率は業界平均以下', OpenIssue::sole()->claim);
    }

    public function test_企業ページに論点と未解消件数が渡る(): void
    {
        [$company] = $this->companyWithVerdicts();
        $this->issue($company, '残業は月20時間程度');
        $this->issue($company, '離職率は業界平均以下', IssueStatus::Resolved);

        $this->get(route('companies.show', $company))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('openIssues.pendingCount', 1)
                ->count('openIssues.items', 2)
                // 追跡中の論点は P2 の判定行にも紐づく（画面が「追う」ボタンを出し分ける）
                ->where('karte.p2.verdicts.0.issueId', OpenIssue::where('claim', '残業は月20時間程度')->value('id'))
                // 状態も渡す（テーブルから解除してよいのは未確認のものだけ）
                ->where('karte.p2.verdicts.0.issueStatus', IssueStatus::Open->value)
            );
    }

    public function test_未追跡の判定行には論点の状態が付かない(): void
    {
        [$company] = $this->companyWithVerdicts();

        $this->get(route('companies.show', $company))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('karte.p2.verdicts.0.issueId', null)
                ->where('karte.p2.verdicts.0.issueStatus', null)
            );
    }

    public function test_ダッシュボードに未解消の論点が出る(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $this->issue($company, '残業は月20時間程度');

        $this->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('openIssues.total', 1)
                ->where('openIssues.items.0.pendingCount', 1)
            );
    }

    /** @return array{0: Company, 1: AnalysisRun} */
    private function companyWithVerdicts(): array
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $run = $company->analysisRuns()->create([
            'status' => RunStatus::Done,
            'finished_at' => now(),
        ]);
        $run->steps()->create([
            'step' => AnalysisStep::P2->value,
            'status' => StepStatus::Done,
            'output_json' => ['verdicts' => [
                [
                    'claim' => '残業は月20時間程度',
                    'verdict' => 'contradict',
                    'rationale' => '口コミでは月45時間の記述がある',
                    'source' => '求人票',
                    'checked_against' => '口コミ',
                    'source_ids' => [],
                    'claim_index' => 0,
                ],
                [
                    'claim' => '離職率は業界平均以下',
                    'verdict' => VerdictConsensus::SPLIT,
                    'rationale' => '判定が割れた',
                    'source' => '求人票',
                    'checked_against' => '口コミ',
                    'source_ids' => [],
                    'claim_index' => 1,
                ],
                [
                    'claim' => '年間休日125日',
                    'verdict' => 'match',
                    'rationale' => '公式数値と一致',
                    'source' => '求人票',
                    'checked_against' => '公式数値',
                    'source_ids' => [],
                    'claim_index' => 2,
                ],
            ]],
        ]);

        return [$company, $run];
    }

    private function issue(Company $company, string $claim, IssueStatus $status = IssueStatus::Open): OpenIssue
    {
        return OpenIssue::create([
            'company_id' => $company->id,
            'claim' => $claim,
            'claim_hash' => OpenIssue::hashFor($claim),
            'verdict' => 'contradict',
            'status' => $status,
        ]);
    }

    private function interviewNote(Company $company): SourceDocument
    {
        return $company->sourceDocuments()->create([
            'type' => SourceType::Interview,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '【面接記録】1次 直近1年は月18時間との回答',
        ]);
    }
}
