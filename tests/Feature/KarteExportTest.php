<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Enums\StepStatus;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KarteExportTest extends TestCase
{
    use RefreshDatabase;

    private function companyWithDoneRun(): Company
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $company->sourceDocuments()->create([
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Url,
            'url' => 'https://example.com/job',
            'raw_text' => '求人票本文',
            'fetched_at' => now()->subDay(),
        ]);
        $run = $company->analysisRuns()->create([
            'status' => RunStatus::Done,
            'finished_at' => now()->subHour(),
        ]);
        $run->steps()->create([
            'step' => 'p2',
            'status' => StepStatus::Done,
            'output_json' => ['verdicts' => [
                ['claim' => '残業月20時間', 'source' => '求人票', 'checked_against' => '口コミ', 'verdict' => 'contradict', 'rationale' => '口コミでは月40時間との記述'],
                ['claim' => 'リモート可', 'source' => '求人票', 'checked_against' => '口コミ', 'verdict' => 'match', 'rationale' => '口コミでも週3リモートの記述'],
                ['claim' => '離職率5%', 'source' => '求人票', 'checked_against' => '', 'verdict' => 'unverifiable', 'rationale' => '照合できる材料なし'],
            ]],
        ]);
        $run->steps()->create([
            'step' => 'p5',
            'status' => StepStatus::Done,
            'output_json' => [
                'recommendation' => 'mid',
                'recommendation_reason' => '必須条件は満たすが矛盾が1件ある',
                'must_checks' => [['condition' => 'リモート可', 'status' => 'met', 'evidence' => '求人票に記載']],
                'want_checks' => [],
                'appeal' => '技術投資に積極的',
                'concern' => '残業時間の記述に矛盾',
                'summary_lines' => ['総合的には条件確認が必要'],
            ],
        ]);
        $run->steps()->create([
            'step' => 'p6',
            'status' => StepStatus::Done,
            'output_json' => ['questions' => [[
                'priority' => 'high',
                'question' => '直近半年の平均残業時間を教えてください',
                'targets' => '残業実態',
                'ask_to' => 'engineer',
                'casual_version' => '忙しさって実際どうですか？',
            ]]],
        ]);

        return $company;
    }

    public function test_doneランがあればmarkdownがダウンロードできる(): void
    {
        $company = $this->companyWithDoneRun();

        $response = $this->get("/companies/{$company->id}/export.md")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');

        // 企業名はクリップ由来の値が入り得るためヘッダに素で埋めない。
        // ASCII フォールバック + RFC 5987 の filename*（UTF-8）で渡す
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment; filename="karte-'.now()->format('Ymd').'.md"', $disposition);
        $this->assertStringContainsString(
            "filename*=UTF-8''".rawurlencode('株式会社テスト-karte-'.now()->format('Ymd').'.md'),
            $disposition,
        );

        $body = $response->getContent();
        $this->assertStringContainsString('# 株式会社テスト', $body);
        $this->assertStringContainsString('- おすすめ度: ○ 条件の確認が必要', $body);
        $this->assertStringContainsString('## 総合所見', $body);
        $this->assertStringContainsString('## 主張検証（⚠️矛盾 1 / 🔀判定割れ 0 / ✅整合 1 / ❓検証不能 1）', $body);
        // ❓検証不能も折りたたまず列挙する
        $this->assertStringContainsString('- 離職率5%', $body);
        $this->assertStringContainsString('## 面接での逆質問', $body);
        $this->assertStringContainsString('1. [必ず聞く] 直近半年の平均残業時間を教えてください', $body);
        $this->assertStringContainsString('相手: 現場エンジニア', $body);
        $this->assertStringContainsString('カジュアル版: 忙しさって実際どうですか？', $body);
        $this->assertStringContainsString('## ソース一覧', $body);
        $this->assertStringContainsString('- 求人票 — https://example.com/job', $body);
    }

    /** @param  array<string, mixed>  $salaryBreakdown */
    private function withP3(Company $company, array $salaryBreakdown): void
    {
        $company->analysisRuns()->firstOrFail()->steps()->create([
            'step' => 'p3',
            'status' => StepStatus::Done,
            'output_json' => [
                'concreteness' => ['level' => 'high', 'reason' => '数値が具体的'],
                'rhetoric_flags' => [],
                'salary_breakdown' => $salaryBreakdown,
            ],
        ]);
    }

    public function test_実質給与は_ph_p確定値computedからエクスポートされる(): void
    {
        $company = $this->companyWithDoneRun();
        $this->withP3($company, [
            'has_fixed_overtime' => true,
            'note' => '固定残業45時間分を含む',
            'computed' => [
                'monthly_work_hours' => 173,
                'base_salary_yen' => 240000,
                'effective_hourly_yen' => 1376,
                'unavailable_reason' => null,
            ],
        ]);

        $body = $this->get("/companies/{$company->id}/export.md")->assertOk()->getContent();

        $this->assertStringContainsString('### 実質給与分解', $body);
        $this->assertStringContainsString('基本給: 240,000円/月', $body);
        $this->assertStringContainsString('実質時給: 約1,376円（所定173h換算）', $body);
    }

    public function test_算出不能な実質給与は理由つきでエクスポートされる(): void
    {
        $company = $this->companyWithDoneRun();
        $this->withP3($company, [
            'has_fixed_overtime' => false,
            'note' => '',
            'computed' => [
                'monthly_work_hours' => 173,
                'base_salary_yen' => null,
                'effective_hourly_yen' => null,
                'unavailable_reason' => '月給の記載がないため算出できません',
            ],
        ]);

        $body = $this->get("/companies/{$company->id}/export.md")->assertOk()->getContent();

        $this->assertStringContainsString('基本給・実質時給: 算出不能 — 月給の記載がないため算出できません', $body);
    }

    public function test_computedを持たない旧ランは未保存と明示される(): void
    {
        $company = $this->companyWithDoneRun();
        // 旧形式（AI が base_salary / effective_hourly を文字列で返していた頃）のラン
        $this->withP3($company, [
            'has_fixed_overtime' => true,
            'note' => '固定残業45時間分を含む',
            'base_salary' => '月24万円程度と推定',
            'effective_hourly' => '約1,376円／時',
        ]);

        $body = $this->get("/companies/{$company->id}/export.md")->assertOk()->getContent();

        // 旧キーを読み替えず（互換レイヤーを作らず）再分析を促す
        $this->assertStringContainsString('基本給・実質時給: 未保存（再分析が必要）', $body);
        $this->assertStringNotContainsString('月24万円程度と推定', $body);
    }

    public function test_ブリーフィングは生成日時つきでエクスポートに載る(): void
    {
        $company = $this->companyWithDoneRun();
        $company->update([
            'briefing_json' => [
                'summary_lines' => ['技術投資は積極的だが残業の実態が不明'],
                'open_issues' => ['残業月20時間の根拠が確認できていない'],
                'top_questions' => ['直近半年の平均残業時間を教えてください'],
                'weak_points' => ['前職での担当範囲を短く説明できるように'],
                'note' => 'カルテ生成時点の情報です',
            ],
            // 分析（1時間前）より後に生成 = 最新なので陳腐化の警告は出ない
            'briefing_generated_at' => now()->subMinutes(30),
        ]);

        $body = (string) $this->get("/companies/{$company->id}/export.md")->assertOk()->getContent();

        $this->assertStringContainsString(
            '## 🎤 面接前ブリーフィング（生成: '.now()->subMinutes(30)->format('Y-m-d H:i').'）',
            $body,
            '生成日時を併記すること',
        );
        $this->assertStringContainsString('- 技術投資は積極的だが残業の実態が不明', $body);
        $this->assertStringContainsString('### 未解決の論点', $body);
        $this->assertStringContainsString('- 残業月20時間の根拠が確認できていない', $body);
        $this->assertStringContainsString('### 逆質問トップ5', $body);
        $this->assertStringContainsString('### 突っ込まれそうな点と備え', $body);
        $this->assertStringContainsString('カルテ生成時点の情報です', $body);
        $this->assertStringNotContainsString('⚠️ カルテが再分析で更新されています', $body);
    }

    public function test_生成後に分析が更新されたブリーフィングは警告つきで載る(): void
    {
        $company = $this->companyWithDoneRun();
        $company->update([
            'briefing_json' => [
                'summary_lines' => ['要点1'],
                'open_issues' => [],
                'top_questions' => [],
                'weak_points' => [],
                'note' => '',
            ],
            // 分析（1時間前）より前に生成 = 古い
            'briefing_generated_at' => now()->subHours(3),
        ]);

        $body = (string) $this->get("/companies/{$company->id}/export.md")->assertOk()->getContent();

        $this->assertStringContainsString('⚠️ カルテが再分析で更新されています', $body);
        $this->assertStringContainsString('ブリーフィングを再生成してください', $body);
    }

    public function test_ブリーフィング未生成の企業では見出しが出ない(): void
    {
        $company = $this->companyWithDoneRun();

        $body = (string) $this->get("/companies/{$company->id}/export.md")->assertOk()->getContent();

        $this->assertStringNotContainsString('面接前ブリーフィング', $body);
    }

    public function test_未分析の企業は422でエクスポートできない(): void
    {
        $company = Company::create(['name' => '株式会社未分析']);

        $this->get("/companies/{$company->id}/export.md")
            ->assertStatus(422)
            ->assertSee('分析後にエクスポートできます');
    }

    public function test_実行中ランしか無い企業も422(): void
    {
        $company = Company::create(['name' => '株式会社実行中']);
        $company->analysisRuns()->create(['status' => RunStatus::Running]);

        $this->get("/companies/{$company->id}/export.md")->assertStatus(422);
    }

    public function test_ai出力に混ざった画像埋め込み記法を無害化する(): void
    {
        // 求人票・口コミ由来の文章がAIの出力に引き写されるため、書き出した .md を
        // Obsidian で開いた瞬間に外部へ画像を取りに行く記法が混ざりうる（閲覧の検知・IP漏洩）。
        // クリックが要るリンク記法は残し、自動で読み込まれる画像だけを打ち消す。
        $company = $this->companyWithDoneRun();
        $step = $company->analysisRuns()->first()->steps()->where('step', 'p2')->first();
        $step->update(['output_json' => ['verdicts' => [[
            'claim' => '残業月20時間',
            'source' => '求人票',
            'checked_against' => '口コミ',
            'verdict' => 'contradict',
            'rationale' => '口コミでは月40時間 ![](https://attacker.example/beacon.png) [詳細](https://example.com/a)',
        ]]]]);

        $body = (string) $this->get("/companies/{$company->id}/export.md")->assertOk()->getContent();

        $this->assertStringNotContainsString('![](https://attacker.example/beacon.png)', $body);
        $this->assertStringContainsString('!\[](https://attacker.example/beacon.png)', $body);
        $this->assertStringContainsString('[詳細](https://example.com/a)', $body, 'リンク記法はそのまま残す');
    }
}
