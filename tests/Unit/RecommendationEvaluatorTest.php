<?php

namespace Tests\Unit;

use App\Enums\Recommendation;
use App\Services\Ai\RecommendationEvaluator;
use PHPUnit\Framework\TestCase;

class RecommendationEvaluatorTest extends TestCase
{
    private function evaluate(array $p5, array $p2 = [], array $p3 = []): Recommendation
    {
        return (new RecommendationEvaluator)->evaluate($p5, $p2, $p3);
    }

    public function test_必須条件に不充足があれば_low(): void
    {
        $p5 = ['must_checks' => [['status' => 'met'], ['status' => 'unmet']], 'want_checks' => []];

        $this->assertSame(Recommendation::Low, $this->evaluate($p5));
    }

    public function test_矛盾が1件あれば_mid(): void
    {
        $p5 = ['must_checks' => [['status' => 'met']], 'want_checks' => []];
        $p2 = ['verdicts' => [['verdict' => 'contradict'], ['verdict' => 'match']]];

        $this->assertSame(Recommendation::Mid, $this->evaluate($p5, $p2));
    }

    public function test_具体性が低ければ_mid(): void
    {
        $p5 = ['must_checks' => [['status' => 'met']], 'want_checks' => []];
        $p3 = ['concreteness' => ['level' => 'low']];

        $this->assertSame(Recommendation::Mid, $this->evaluate($p5, [], $p3));
    }

    public function test_必須条件が未確認なら_mid(): void
    {
        // 材料が無くて確認できていない条件を「満たした」とは扱わない（◎を安売りしない）
        $p5 = ['must_checks' => [
            ['status' => 'met', 'condition' => '年収400万以上'],
            ['status' => 'unknown', 'condition' => 'リモート可'],
        ]];

        $this->assertSame(Recommendation::Mid, $this->evaluate($p5));
        $this->assertStringContainsString(
            'リモート可',
            (new RecommendationEvaluator)->reasonFor($p5, [], []),
        );
    }

    public function test_必須充足_矛盾なし_具体性ありで_high(): void
    {
        $p5 = ['must_checks' => [['status' => 'met']], 'want_checks' => [['status' => 'unknown']]];
        $p2 = ['verdicts' => [['verdict' => 'match'], ['verdict' => 'unverifiable']]];
        $p3 = ['concreteness' => ['level' => 'high']];

        $this->assertSame(Recommendation::High, $this->evaluate($p5, $p2, $p3), '歓迎条件の未確認は減点しない');
    }

    public function test_unverifiableのみでは減点せず_high維持(): void
    {
        $p2 = ['verdicts' => [['verdict' => 'unverifiable'], ['verdict' => 'unverifiable']]];

        $this->assertSame(Recommendation::High, $this->evaluate(['must_checks' => []], $p2));
    }

    public function test_求人票が未検証なら_hold(): void
    {
        // 求人票なし（P1スキップ）で口コミだけの場合、◎を出さず判定保留にする
        $p5 = ['must_checks' => [['status' => 'met']], 'want_checks' => []];

        $this->assertSame(
            Recommendation::Hold,
            (new RecommendationEvaluator)->evaluate($p5, [], [], false),
        );
    }

    public function test_判定理由に不充足の条件名が入る(): void
    {
        $p5 = ['must_checks' => [
            ['status' => 'met', 'condition' => '年収400万以上'],
            ['status' => 'unmet', 'condition' => 'リモート可'],
        ]];

        $reason = (new RecommendationEvaluator)->reasonFor($p5, [], []);

        $this->assertStringContainsString('不充足が1件', $reason);
        $this->assertStringContainsString('リモート可', $reason);
    }

    public function test_判定理由に矛盾件数と具体性が入る(): void
    {
        $p5 = ['must_checks' => [['status' => 'met', 'condition' => '年収']]];
        $p2 = ['verdicts' => [['verdict' => 'contradict'], ['verdict' => 'contradict']]];
        $p3 = ['concreteness' => ['level' => 'low']];

        $reason = (new RecommendationEvaluator)->reasonFor($p5, $p2, $p3);

        $this->assertStringContainsString('矛盾が2件', $reason);
        $this->assertStringContainsString('具体性が低い', $reason);
    }

    public function test_ng条件にhitが1件あれば_lowで理由に条件名が入る(): void
    {
        $p5 = [
            'must_checks' => [['status' => 'met', 'condition' => '年収400万以上']],
            'want_checks' => [],
            'ng_checks' => [['status' => 'hit', 'condition' => '休日出勤あり']],
        ];

        $this->assertSame(Recommendation::Low, $this->evaluate($p5));
        $this->assertStringContainsString(
            'NG条件『休日出勤あり』に該当の可能性',
            (new RecommendationEvaluator)->reasonFor($p5, [], []),
        );
    }

    public function test_ng条件がunknownとclearのみなら従来判定と同じ(): void
    {
        // unknown は保留扱いで判定に影響させない（hit の確定はPHP側の分岐のみで行う）
        $p5 = [
            'must_checks' => [['status' => 'met']],
            'want_checks' => [],
            'ng_checks' => [
                ['status' => 'unknown', 'condition' => '休日出勤あり'],
                ['status' => 'clear', 'condition' => '転勤あり'],
            ],
        ];

        $this->assertSame(Recommendation::High, $this->evaluate($p5));
    }

    public function test_ng_checksキーが無い旧データでも従来判定のままエラーにならない(): void
    {
        // ng_checks 導入(2026-07)以前のランには ng_checks キーが存在しない
        $p5 = ['must_checks' => [['status' => 'met']], 'want_checks' => []];

        $this->assertSame(Recommendation::High, $this->evaluate($p5));
    }

    public function test_判定理由は保留と充足でも出る(): void
    {
        $evaluator = new RecommendationEvaluator;
        $p5 = ['must_checks' => [['status' => 'met', 'condition' => '年収']]];

        $this->assertStringContainsString('求人票が未投入', $evaluator->reasonFor($p5, [], [], false));
        $this->assertStringContainsString('矛盾も見つかりませんでした', $evaluator->reasonFor($p5, [], []));
    }

    public function test_危険サインは判定を変えず理由文に併記される(): void
    {
        // 注意表現はほぼ全ての求人票で出るため判定条件には入れない。ただし理由文には必ず出す
        $p5 = ['must_checks' => [['status' => 'met', 'condition' => '年収400万以上']], 'want_checks' => []];
        $p3 = [
            'rhetoric_flags' => [
                ['phrase' => 'アットホーム', 'quote' => '', 'concern' => ''],
                ['phrase' => '若手が活躍', 'quote' => '', 'concern' => ''],
                ['phrase' => '成長できる環境', 'quote' => '', 'concern' => ''],
            ],
            'concreteness' => ['level' => 'high', 'reason' => ''],
            'salary_breakdown' => ['has_fixed_overtime' => true],
        ];
        $evaluator = new RecommendationEvaluator;

        $this->assertSame(Recommendation::High, $evaluator->evaluate($p5, [], $p3), '危険サインで判定は下げない');

        $reason = $evaluator->reasonFor($p5, [], $p3);
        $this->assertStringContainsString('矛盾も見つかりませんでした', $reason);
        $this->assertStringContainsString('注意表現3件', $reason);
        $this->assertStringContainsString('固定残業の記載あり', $reason);
        $this->assertStringContainsString('危険サイン参照', $reason);
    }

    public function test_危険サインが無ければ理由文に併記されない(): void
    {
        $p5 = ['must_checks' => [['status' => 'met', 'condition' => '年収400万以上']]];
        $p3 = [
            'rhetoric_flags' => [],
            'concreteness' => ['level' => 'high', 'reason' => ''],
            'salary_breakdown' => ['has_fixed_overtime' => false],
        ];

        $reason = (new RecommendationEvaluator)->reasonFor($p5, [], $p3);

        $this->assertStringNotContainsString('注意表現', $reason);
        $this->assertStringNotContainsString('固定残業', $reason);
    }

    public function test_危険サインはlow判定の理由文にも併記される(): void
    {
        $p5 = ['must_checks' => [['status' => 'unmet', 'condition' => 'リモート可']]];
        $p3 = [
            'rhetoric_flags' => [['phrase' => '裁量が大きい', 'quote' => '', 'concern' => '']],
            'salary_breakdown' => ['has_fixed_overtime' => false],
        ];

        $reason = (new RecommendationEvaluator)->reasonFor($p5, [], $p3);

        $this->assertStringContainsString('不充足が1件', $reason);
        $this->assertStringContainsString('注意表現1件', $reason);
    }

    public function test_p3が未保存のランでは危険サインを未判定と明示する(): void
    {
        // P3 の出力が無いランで「危険サインなし（安全）」と読ませない
        $p5 = ['must_checks' => [['status' => 'met', 'condition' => '年収400万以上']]];

        $reason = (new RecommendationEvaluator)->reasonFor($p5, [], []);

        $this->assertStringContainsString('危険サイン', $reason);
        $this->assertStringContainsString('未判定', $reason);
    }

    public function test_求人票未投入の保留では危険サインに言及しない(): void
    {
        // 求人票が無ければ P3 自体が動かない。保留理由と重複する注記は出さない
        $p5 = ['must_checks' => [['status' => 'met', 'condition' => '年収400万以上']]];

        $reason = (new RecommendationEvaluator)->reasonFor($p5, [], [], false);

        $this->assertStringNotContainsString('危険サイン', $reason);
    }

    public function test_おすすめ度のラベルは読める日本語(): void
    {
        $this->assertSame('○ 条件の確認が必要', Recommendation::Mid->label());
    }
}
