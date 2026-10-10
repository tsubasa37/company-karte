<?php

namespace Tests\Feature;

use App\Enums\MetricSource;
use App\Enums\RunStatus;
use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Enums\StepStatus;
use App\Models\Company;
use App\Models\Profile;
use App\Models\ReviewItem;
use App\Models\ReviewJudgment;
use App\Services\MaterialFreshness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CompanyShowTest extends TestCase
{
    use RefreshDatabase;

    private function companyWithDoneRun(): Company
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $run = $company->analysisRuns()->create([
            'status' => RunStatus::Done,
            'finished_at' => now()->subHour(),
            // 本番では AnalyzeCompanyJob が開始時に残す。鮮度判定の基準なので同じ形で入れる
            'materials_json' => app(MaterialFreshness::class)->snapshot($company),
        ]);
        $run->steps()->create([
            'step' => 'p2',
            'status' => StepStatus::Done,
            'output_json' => ['verdicts' => [
                ['claim' => 'A', 'source' => '', 'checked_against' => '', 'verdict' => 'unverifiable', 'rationale' => ''],
                ['claim' => 'B', 'source' => '', 'checked_against' => '', 'verdict' => 'contradict', 'rationale' => ''],
            ]],
        ]);

        return $company;
    }

    public function test_最新ランが実行中でもカルテは前回の完了ランを表示する(): void
    {
        $company = $this->companyWithDoneRun();
        $doneRunId = $company->analysisRuns()->first()->id;
        // 再分析でキュー投入された状態
        $company->analysisRuns()->create(['status' => RunStatus::Queued]);

        $this->get("/companies/{$company->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Companies/Show')
                ->where('karte.runId', $doneRunId)
                ->where('run.status', 'queued'));
    }

    public function test_最新ランが失敗でもカルテは消えない(): void
    {
        $company = $this->companyWithDoneRun();
        $company->analysisRuns()->create(['status' => RunStatus::Failed, 'error' => 'codex timeout']);

        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('run.status', 'failed')
                ->has('karte')
                ->where('karte.verdictCounts.contradict', 1));
    }

    public function test_p2の判定は矛盾が先頭に並び替えられる(): void
    {
        $company = $this->companyWithDoneRun();

        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('karte.p2.verdicts.0.verdict', 'contradict'));
    }

    public function test_切り捨て警告は種別ごとの上限で判定される(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        // 求人票の上限は 20,000 字。12,000 字では切り捨てにならない
        $company->sourceDocuments()->create([
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Paste,
            'raw_text' => str_repeat('あ', 12000),
        ]);

        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('sources.0.isTruncated', false)
                ->where('sources.0.limit', 20000));
    }

    public function test_タブ指定のクエリがプロップに渡る(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        $this->get("/companies/{$company->id}?tab=info")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('activeTab', 'info'));
        $this->get("/companies/{$company->id}?tab=evaluation")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('activeTab', 'evaluation'));
        $this->get("/companies/{$company->id}?tab=interview")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('activeTab', 'interview'));
        $this->get("/companies/{$company->id}?tab=bogus")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('activeTab', null));
    }

    public function test_旧タブ名karteは評価タブとして受ける(): void
    {
        // カルテタブを評価・面接準備に割る前の ?tab=karte を、ダッシュボードや検索が今も送ってくる
        $company = Company::create(['name' => '株式会社テスト']);

        $this->get("/companies/{$company->id}?tab=karte")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('activeTab', 'evaluation'));
    }

    public function test_分析できない理由が事前に渡る(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('analyzeBlocked.action', 'sources'));
    }

    public function test_面接記録を保存すると面接メモソースになる(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        $this->post("/companies/{$company->id}/interviews", [
            'interviewed_on' => '2026-07-29',
            'round' => '一次面接',
            'interviewer' => '開発部長',
            'asked' => '残業時間の実態',
            'answers' => '平均で月20時間、繁忙期は30時間程度とのこと',
            'impressions' => '率直に答えてくれた',
        ])->assertRedirect();

        $source = $company->sourceDocuments()->first();
        $this->assertSame(SourceType::Interview, $source->type);
        $this->assertStringContainsString('【面接記録】一次面接（2026-07-29）', $source->raw_text);
        $this->assertStringContainsString('平均で月20時間', $source->raw_text);
        // 追記専用: もう1件保存しても上書きされず増える
        $this->post("/companies/{$company->id}/interviews", [
            'interviewed_on' => '2026-08-05',
            'round' => '二次面接',
            'answers' => '配属チームは5名構成',
        ]);
        $this->assertSame(2, $company->sourceDocuments()->count());
    }

    public function test_面接記録の短い行はノイズ除去で消えない(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        // 「利用規約」「Cookie」は Web ページ用のノイズ辞書に部分一致するが、
        // 面接メモは本人が書いた一次情報なのでクリーナを通さず無傷で保存されること
        $this->post("/companies/{$company->id}/interviews", [
            'interviewed_on' => '2026-07-29',
            'round' => '一次面接',
            'answers' => "利用規約の話も出た\nCookieポリシーの件は未確認\n平均残業は月20時間とのこと",
        ])->assertRedirect();

        $source = $company->sourceDocuments()->first();
        $this->assertStringContainsString('利用規約の話も出た', $source->raw_text);
        $this->assertStringContainsString('Cookieポリシーの件は未確認', $source->raw_text);
        $this->assertStringContainsString('平均残業は月20時間とのこと', $source->raw_text);
    }

    public function test_面接記録は回答が必須(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        $this->post("/companies/{$company->id}/interviews", [
            'interviewed_on' => '2026-07-29',
            'round' => '一次面接',
        ])->assertSessionHasErrors('answers');
    }

    /**
     * P1/P2 を持つ完了ランを1件作る。
     *
     * @param  array<int, array<string, mixed>>  $claims
     * @param  array<int, array<string, mixed>>  $verdicts
     */
    private function runWith(Company $company, array $claims, array $verdicts): void
    {
        // 実際の P2 は claim_index（P1 claims 配列の添字）を引用して返す。
        // フィクスチャでは claim テキストが一致する添字を補って同じ形にする
        // （claim_index を明示済みの行と、どの主張にも対応しない行はそのまま）
        $indexByClaim = array_flip(array_map(fn ($c) => (string) ($c['claim'] ?? ''), $claims));
        $verdicts = array_map(function (array $v) use ($indexByClaim) {
            if (! array_key_exists('claim_index', $v) && isset($indexByClaim[$v['claim'] ?? ''])) {
                $v['claim_index'] = $indexByClaim[$v['claim']];
            }

            return $v;
        }, $verdicts);

        $run = $company->analysisRuns()->create([
            'status' => RunStatus::Done,
            'finished_at' => now()->subHour(),
        ]);
        $run->steps()->create([
            'step' => 'p1',
            'status' => StepStatus::Done,
            'output_json' => ['claims' => $claims, 'dropped_claims' => [], 'dropped_claims_count' => 0],
        ]);
        $run->steps()->create([
            'step' => 'p2',
            'status' => StepStatus::Done,
            'output_json' => ['verdicts' => $verdicts],
        ]);
    }

    /**
     * @param  array<int, string>  $verdicts
     * @return array<int, array<string, mixed>>
     */
    private function verdictRows(array $verdicts): array
    {
        $rows = [];
        foreach ($verdicts as $claim => $verdict) {
            $rows[] = [
                'claim' => $claim,
                'source' => '',
                'checked_against' => '',
                'verdict' => $verdict,
                'rationale' => '',
            ];
        }

        return $rows;
    }

    public function test_同じ判定の主張は重要度の高い順に並ぶ(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $this->runWith(
            $company,
            [
                ['claim' => '駅から徒歩3分', 'quote' => '', 'category' => 'other', 'verifiable' => true, 'importance' => 'low'],
                ['claim' => '残業月20時間', 'quote' => '', 'category' => 'overtime', 'verifiable' => true, 'importance' => 'high'],
            ],
            $this->verdictRows(['駅から徒歩3分' => 'unverifiable', '残業月20時間' => 'unverifiable']),
        );

        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                // 判定が同じなら重要度が第2ソートキーになる
                ->where('karte.p2.verdicts.0.claim', '残業月20時間')
                ->where('karte.p2.verdicts.0.importance', 'high')
                ->where('karte.p2.verdicts.1.claim', '駅から徒歩3分')
                ->where('karte.importanceNote', null));
    }

    public function test_重要度lowの主張は畳まれて本命の主張を埋めない(): void
    {
        // 「設立年」「所在地」「法定義務の制度」で一覧が埋まると、本当に確認すべき主張が見えなくなる
        $company = Company::create(['name' => '株式会社テスト']);
        $this->runWith(
            $company,
            [
                ['claim' => '社会保険を完備している', 'quote' => '', 'category' => 'other', 'verifiable' => true, 'importance' => 'low'],
                ['claim' => '残業月20時間', 'quote' => '', 'category' => 'overtime', 'verifiable' => true, 'importance' => 'high'],
            ],
            $this->verdictRows(['社会保険を完備している' => 'unverifiable', '残業月20時間' => 'unverifiable']),
        );

        // 畳むのは画面側のグループ分け。PHP は「どれが low か」を渡すところまでを保証する
        // （データを間引くと後から確認できなくなるため、件数は減らさない）
        $this->get("/companies/{$company->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('karte.p2.verdicts', 2)
                ->where('karte.p2.verdicts.0.claim', '残業月20時間')
                ->where('karte.p2.verdicts.0.importance', 'high')
                ->where('karte.p2.verdicts.1.claim', '社会保険を完備している')
                ->where('karte.p2.verdicts.1.importance', 'low')
                // 全体の件数は畳んでも変わらない（サマリの数字と一覧の合計が食い違わない）
                ->where('karte.verdictCounts.unverifiable', 2));
    }

    public function test_p1プロンプトは法定義務の制度を低い重要度と明示する(): void
    {
        // 判定がぶれると畳む対象も変わるため、基準はプロンプト側で具体的に固定しておく
        $prompt = (string) file_get_contents(resource_path('prompts/p1.txt'));

        $this->assertStringContainsString('法律で全社に義務付けられている制度', $prompt);
        $this->assertStringContainsString('社会保険', $prompt);
        $this->assertStringContainsString('介護休暇', $prompt);
        // 制度の有無ではなく実態（取得率）まで low にしないこと
        $this->assertStringContainsString('有給取得率', $prompt);
    }

    public function test_判定が違えば重要度より判定が優先される(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $this->runWith(
            $company,
            [
                ['claim' => '駅から徒歩3分', 'quote' => '', 'category' => 'other', 'verifiable' => true, 'importance' => 'low'],
                ['claim' => '残業月20時間', 'quote' => '', 'category' => 'overtime', 'verifiable' => true, 'importance' => 'high'],
            ],
            $this->verdictRows(['駅から徒歩3分' => 'contradict', '残業月20時間' => 'unverifiable']),
        );

        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('karte.p2.verdicts.0.claim', '駅から徒歩3分'));
    }

    public function test_p1に無い主張の重要度はnullのまま末尾に置かれる(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $this->runWith(
            $company,
            [['claim' => '残業月20時間', 'quote' => '', 'category' => 'overtime', 'verifiable' => true, 'importance' => 'low']],
            $this->verdictRows(['言い換えられた主張' => 'unverifiable', '残業月20時間' => 'unverifiable']),
        );

        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                // 引き当てられなかった主張を mid 扱いにせず、null のまま末尾に置く
                ->where('karte.p2.verdicts.0.claim', '残業月20時間')
                ->where('karte.p2.verdicts.1.claim', '言い換えられた主張')
                ->where('karte.p2.verdicts.1.importance', null));
    }

    public function test_重要度が未保存の旧ランは並び順を変えず明示する(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $this->runWith(
            $company,
            // importance 導入以前のランには importance キーが無い
            [
                ['claim' => '駅から徒歩3分', 'quote' => '', 'category' => 'other', 'verifiable' => true],
                ['claim' => '残業月20時間', 'quote' => '', 'category' => 'overtime', 'verifiable' => true],
            ],
            $this->verdictRows(['駅から徒歩3分' => 'unverifiable', '残業月20時間' => 'unverifiable']),
        );

        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                // 黙って mid で埋めず、順序は元のまま
                ->where('karte.p2.verdicts.0.claim', '駅から徒歩3分')
                ->where('karte.importanceNote', '主張の重要度はこのランには未保存です（並び順は判定のみ。再分析すると重要度の高い主張が先頭に並びます）'));
    }

    public function test_再分析で判定が変わった主張が差分に出る(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $this->runWith($company, [], $this->verdictRows([
            '残業月20時間' => 'unverifiable',
            '離職率5%' => 'unverifiable',
        ]));
        $this->runWith($company, [], $this->verdictRows([
            '残業月20時間' => 'match',
            '離職率5%' => 'unverifiable',
            '有給消化率90%' => 'contradict',
        ]));

        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('karte.diff.changes.total', 2)
                // 矛盾が先頭。前回に無かった主張は「未抽出」と明示する
                ->where('karte.diff.changes.items.0.claim', '有給消化率90%')
                ->where('karte.diff.changes.items.0.prevLabel', '（前回は未抽出）')
                ->where('karte.diff.changes.items.0.currLabel', '矛盾')
                ->where('karte.diff.changes.items.1.claim', '残業月20時間')
                ->where('karte.diff.changes.items.1.prevLabel', '検証不能')
                ->where('karte.diff.changes.items.1.currLabel', '整合')
                ->where('karte.diff.changes.unavailableReason', null));
    }

    public function test_前回ランにp2が無ければ判定変化は比較できない旨を出す(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        // P2 をスキップした（口コミ等の材料が無い）ラン
        $prev = $company->analysisRuns()->create(['status' => RunStatus::Done, 'finished_at' => now()->subDay()]);
        $prev->steps()->create([
            'step' => 'p2',
            'status' => StepStatus::Skipped,
            'output_json' => ['skipped' => true, 'reason' => '素材未投入'],
        ]);
        $this->runWith($company, [], $this->verdictRows(['残業月20時間' => 'match']));

        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('karte.diff.changes.items', [])
                ->where('karte.diff.changes.unavailableReason', '前回のランに主張検証（P2）の結果が無いため、判定の変化は比較できません。'));
    }

    public function test_分析後に増えたソースと公式数値が鮮度に出る(): void
    {
        $company = $this->companyWithDoneRun();
        // ラン完了（1時間前）より後に投入された材料
        $company->sourceDocuments()->create([
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '求人票',
        ]);
        $company->officialMetrics()->create([
            'source' => MetricSource::Shokuba,
            'key' => '月平均所定外労働時間',
            'value' => '18',
        ]);

        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('karte.freshness.isStale', true)
                ->where('karte.freshness.newSources', 1)
                ->where('karte.freshness.newMetrics', 1)
                ->where('karte.freshness.unavailableReason', null));
    }

    public function test_分析中に投入した材料も鮮度に出る(): void
    {
        Queue::fake();
        $company = $this->companyWithDoneRun();
        $run = $company->analysisRuns()->first();

        // ラン開始時の記録は空。開始〜完了の間に投入された材料は「完了より古い」ため
        // 終了時刻の比較では見逃す（このランの分析には載っていない）
        $source = $company->sourceDocuments()->create([
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '分析中に貼った求人票',
        ]);
        DB::table('source_documents')->where('id', $source->id)
            ->update(['created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2)]);
        $this->assertTrue($source->fresh()->updated_at->lt($run->finished_at), '完了時刻より前に見える材料');

        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('karte.freshness.isStale', true)
                ->where('karte.freshness.newSources', 1));
    }

    public function test_分析後に削除した材料が鮮度に出る(): void
    {
        Queue::fake();
        $company = Company::create(['name' => '株式会社テスト']);
        $source = $company->sourceDocuments()->create([
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '求人票',
        ]);
        $run = $company->analysisRuns()->create([
            'status' => RunStatus::Done,
            'finished_at' => now()->subHour(),
            'materials_json' => app(MaterialFreshness::class)->snapshot($company->fresh()),
        ]);
        $run->steps()->create(['step' => 'p2', 'status' => StepStatus::Done, 'output_json' => ['verdicts' => []]]);

        $this->delete(route('sources.destroy', $source))->assertSessionDoesntHaveErrors();

        // 削除は材料の更新時刻を動かさない。記録との差分でのみ検知できる
        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('karte.freshness.isStale', true)
                ->where('karte.freshness.removedSources', 1)
                ->where('karte.freshness.newSources', 0));
    }

    public function test_材料の記録が無いランは判定できない範囲を明示する(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        // この列が入る前に作られたラン（記録なし）
        $run = $company->analysisRuns()->create([
            'status' => RunStatus::Done,
            'finished_at' => now()->subHour(),
        ]);
        $run->steps()->create(['step' => 'p2', 'status' => StepStatus::Done, 'output_json' => ['verdicts' => []]]);

        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('karte.freshness.removedSources', 0)
                ->where('karte.freshness.unavailableReason',
                    'このランには材料の記録がないため、分析中に差し替えた材料と分析後に削除した材料は判定できません（次の分析から記録します）。'));
    }

    public function test_分析後に材料が増えていなければ鮮度は最新のまま(): void
    {
        $company = $this->companyWithDoneRun();

        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('karte.freshness.isStale', false)
                ->where('karte.freshness.newSources', 0));
    }

    public function test_終了時刻が無いランは鮮度を判定できない旨を返す(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $run = $company->analysisRuns()->create(['status' => RunStatus::Done]);
        $run->steps()->create(['step' => 'p2', 'status' => StepStatus::Done, 'output_json' => ['verdicts' => []]]);

        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('karte.freshness.isStale', false)
                ->where('karte.freshness.unavailableReason', 'このランには終了時刻が保存されていないため、分析後に増えた材料を判定できません。'));
    }

    public function test_労基突合は未突合と該当なしと該当ありを区別できる(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        // (1) 未突合
        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('company.laborCheckedAt', null)
                ->where('company.laborNote', null));

        // (2) 突合済み・該当なし
        $company->update(['labor_checked_at' => now()->setDate(2026, 7, 30)->setTime(9, 0)]);
        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('company.laborCheckedAt', '2026-07-30 09:00')
                ->where('company.laborNote', null));

        // (3) 該当あり
        $company->update(['labor_note' => '労基公表リストに社名一致（要確認）']);
        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('company.laborCheckedAt', '2026-07-30 09:00')
                ->where('company.laborNote', '労基公表リストに社名一致（要確認）')
                ->where('company.laborNoteHistory', []));
    }

    public function test_過去に一致していた注記の履歴が新しい順で渡る(): void
    {
        $company = Company::create([
            'name' => '株式会社テスト',
            'labor_checked_at' => now(),
            'labor_note_history' => [
                ['note' => '古い一致', 'reason' => '社名一致なし', 'cleared_at' => '2026-05-01T10:00:00+09:00'],
                ['note' => '新しい一致', 'reason' => '突合対象外', 'cleared_at' => '2026-07-01T11:30:00+09:00'],
            ],
        ]);

        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('company.laborNoteHistory.0.note', '新しい一致')
                ->where('company.laborNoteHistory.0.clearedAt', '2026-07-01 11:30')
                ->where('company.laborNoteHistory.1.note', '古い一致'));
    }

    public function test_必須条件が未設定でも分析理由は設定画面を指す(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $company->sourceDocuments()->create([
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '求人票',
        ]);
        Profile::query()->delete();

        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('analyzeBlocked.action', 'settings'));
    }

    /**
     * P1/P2 を持つ完了ランに、口コミの機械判定（RJ）の行を足す。主張 0 は 3 件とも矛盾、主張 1 は判定しない。
     */
    private function companyWithMachineJudgments(): Company
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $this->runWith(
            $company,
            [
                ['claim' => '残業月20時間', 'quote' => '', 'category' => 'overtime', 'verifiable' => true, 'importance' => 'high'],
                ['claim' => '在宅勤務可', 'quote' => '', 'category' => 'other', 'verifiable' => true, 'importance' => 'high'],
            ],
            $this->verdictRows(['残業月20時間' => 'match', '在宅勤務可' => 'unverifiable']),
        );
        $run = $company->analysisRuns()->latest('id')->first();
        $doc = $company->sourceDocuments()->create([
            'type' => SourceType::Review,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '口コミ',
            'split_at' => now(),
        ]);

        foreach (['残業は月60時間', '毎日終電', '繁忙期は休日出勤'] as $body) {
            $item = ReviewItem::create([
                'company_id' => $company->id,
                'source_document_id' => $doc->id,
                'body' => $body,
                'body_hash' => sha1($body),
            ]);
            ReviewJudgment::create([
                'analysis_run_id' => $run->id,
                'review_item_id' => $item->id,
                'claim_index' => 0,
                'claim_hash' => hash('sha256', '残業月20時間'),
                'relation' => 'contradict',
                'p_contradict' => 0.9,
                'p_support' => 0.05,
                'p_none' => 0.05,
                'confidence' => 0.8,
                'model' => 'jev-1.13.0',
            ]);
        }

        return $company;
    }

    public function test_機械判定のあるランは判定行に主張ごとの集計が付く(): void
    {
        $company = $this->companyWithMachineJudgments();
        $sourceId = $company->sourceDocuments()->first()->id;
        $firstItemId = ReviewItem::orderBy('id')->first()->id;

        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('karte.hasMachineJudgment', true)
                ->where('karte.p2.verdicts.0.claim', '残業月20時間')
                ->where('karte.p2.verdicts.0.machine.summary', '口コミ 3 件を機械判定: 矛盾 3・整合 0・言及なし 0・保留 0')
                ->where('karte.p2.verdicts.0.machine.counts.contradict', 3)
                ->where('karte.p2.verdicts.0.machine.contradictItems.0', ['itemId' => $firstItemId, 'sourceId' => $sourceId])
                // 整合と判定した主張に、矛盾とされた口コミが 3 件（しきい値）ある
                ->where('karte.p2.verdicts.0.machine.flag', 'contradict_reviews')
                // 判定していない主張（間引いた主張など）は集計が無い
                ->where('karte.p2.verdicts.1.claim', '在宅勤務可')
                ->where('karte.p2.verdicts.1.machine', null));
    }

    public function test_機械判定のないランは集計がnullで列も出さない(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $this->runWith(
            $company,
            [['claim' => '残業月20時間', 'quote' => '', 'category' => 'overtime', 'verifiable' => true, 'importance' => 'high']],
            $this->verdictRows(['残業月20時間' => 'match']),
        );

        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('karte.hasMachineJudgment', false)
                ->where('karte.p2.verdicts.0.machine', null));
    }

    public function test_進捗のステップは実行順に並び判定エンジン未設定の機械判定スキップは出さない(): void
    {
        $company = $this->companyWithDoneRun();
        $run = $company->analysisRuns()->first();
        $run->steps()->create(['step' => 'rj', 'status' => StepStatus::Skipped, 'output_json' => ['skipped' => true, 'reason' => '判定エンジン未設定']]);
        $run->steps()->create(['step' => 'p1', 'status' => StepStatus::Done, 'output_json' => ['claims' => []]]);

        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('run.steps', 2)
                ->where('run.steps.0.step', 'p1')
                ->where('run.steps.1.step', 'p2')
                // RJ の出力（口コミごとの確率）もスキップ理由もカルテには載せない
                ->missing('karte.rj')
                ->missing('karte.skipped.rj'));
    }

    public function test_判定した機械判定は実行順でp1の直後に並ぶ(): void
    {
        $company = $this->companyWithDoneRun();
        $run = $company->analysisRuns()->first();
        $run->steps()->create(['step' => 'rj', 'status' => StepStatus::Done, 'output_json' => ['relevance' => [1 => 0.9]]]);
        $run->steps()->create(['step' => 'p1', 'status' => StepStatus::Done, 'output_json' => ['claims' => []]]);

        $this->get("/companies/{$company->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('run.steps.0.step', 'p1')
                ->where('run.steps.1.step', 'rj')
                ->where('run.steps.2.step', 'p2')
                ->missing('karte.rj'));
    }
}
