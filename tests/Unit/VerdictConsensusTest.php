<?php

namespace Tests\Unit;

use App\Enums\VerdictPerspective;
use App\Services\Ai\VerdictConsensus;
use PHPUnit\Framework\TestCase;

/**
 * 複数の判定者が出した P2 の判定を PHP 側で1つに確定するルールを固定する。
 *
 * - 過半数があればその判定
 * - 意見が実際に分かれて過半数が作れなければ split（判定割れ）
 * - 意見は割れていないが判定した人数が足りなければ unverifiable（材料不足と同じ扱い）
 */
class VerdictConsensusTest extends TestCase
{
    private VerdictConsensus $consensus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->consensus = new VerdictConsensus;
    }

    public function test_人数1なら判定も行の並びも変えない(): void
    {
        $result = $this->consensus->merge([
            $this->opinion(VerdictPerspective::Base, [
                $this->row(0, 'contradict', '公式数値と食い違う'),
                $this->row(1, 'match', '公式数値と一致'),
            ]),
        ]);

        $this->assertCount(2, $result['verdicts']);
        $this->assertSame('contradict', $result['verdicts'][0]['verdict']);
        $this->assertSame('公式数値と食い違う', $result['verdicts'][0]['rationale']);
        // 並びは判定者の出力順のまま（多重化前と同じ）
        $this->assertSame(1, $result['verdicts'][1]['claim_index']);

        $consensus = $result['verdicts'][0]['consensus'];
        $this->assertSame(1, $consensus['panel_size']);
        $this->assertSame(1, $consensus['agreed']);
        $this->assertTrue($consensus['is_unanimous']);
        $this->assertSame('majority', $consensus['decided_by']);
    }

    public function test_3人中2人が同じ判定なら多数決で確定する(): void
    {
        $result = $this->consensus->merge([
            $this->opinion(VerdictPerspective::Base, [$this->row(0, 'match', '1人目の根拠')]),
            $this->opinion(VerdictPerspective::Official, [$this->row(0, 'contradict', '2人目の根拠')]),
            $this->opinion(VerdictPerspective::Review, [$this->row(0, 'match', '3人目の根拠')]),
        ]);

        $verdict = $result['verdicts'][0];
        $this->assertSame('match', $verdict['verdict']);
        $this->assertSame(2, $verdict['consensus']['agreed']);
        // 全員一致ではない（1人が違う判定を出している）
        $this->assertFalse($verdict['consensus']['is_unanimous']);
        $this->assertSame('majority', $verdict['consensus']['decided_by']);
        $this->assertSame('3人中2人が一致', $verdict['consensus']['summary']);
        $this->assertSame(['match' => 2, 'contradict' => 1, 'unverifiable' => 0], $verdict['consensus']['counts']);
        // 代表行は多数派のうち最も若い番号の判定者（ここでは1人目）のもの
        $this->assertSame('1人目の根拠', $verdict['rationale']);
        // 少数意見も消さず内訳に残す
        $this->assertSame('2人目の根拠', $verdict['consensus']['opinions'][1]['rationale']);
        $this->assertSame('contradict', $verdict['consensus']['opinions'][1]['verdict']);
    }

    public function test_全員一致なら一致として記録する(): void
    {
        $result = $this->consensus->merge([
            $this->opinion(VerdictPerspective::Base, [$this->row(0, 'match')]),
            $this->opinion(VerdictPerspective::Official, [$this->row(0, 'match')]),
            $this->opinion(VerdictPerspective::Review, [$this->row(0, 'match')]),
        ]);

        $consensus = $result['verdicts'][0]['consensus'];
        $this->assertSame(3, $consensus['agreed']);
        $this->assertTrue($consensus['is_unanimous']);
    }

    public function test_3人がばらばらなら判定割れとして確定する(): void
    {
        $result = $this->consensus->merge([
            $this->opinion(VerdictPerspective::Base, [$this->row(0, 'match', '1人目の根拠')]),
            $this->opinion(VerdictPerspective::Official, [$this->row(0, 'contradict')]),
            $this->opinion(VerdictPerspective::Review, [$this->row(0, 'unverifiable')]),
        ]);

        $verdict = $result['verdicts'][0];
        // 材料不足（unverifiable）と混ぜず、解釈が割れたことを示す独立した値で確定する
        $this->assertSame(VerdictConsensus::SPLIT, $verdict['verdict']);
        $this->assertSame('split', $verdict['consensus']['decided_by']);
        // 割れたときは「何人が一致したか」に意味が無い
        $this->assertNull($verdict['consensus']['agreed']);
        $this->assertStringContainsString('面接で事実を確認する論点', $verdict['consensus']['summary']);
        // 3人の判定がすべて内訳に残る
        $this->assertCount(3, $verdict['consensus']['opinions']);
    }

    public function test_2人で割れたら判定割れとして確定する(): void
    {
        $result = $this->consensus->merge([
            $this->opinion(VerdictPerspective::Base, [$this->row(0, 'match', '1人目の根拠')]),
            $this->opinion(VerdictPerspective::Official, [$this->row(0, 'contradict')]),
        ]);

        $verdict = $result['verdicts'][0];
        $this->assertSame(VerdictConsensus::SPLIT, $verdict['verdict']);
        $this->assertSame('split', $verdict['consensus']['decided_by']);
        // 誰も split とは判定していないので、代表行は1人目のものを使う
        $this->assertSame('1人目の根拠', $verdict['rationale']);
    }

    public function test_2人が一致すればその判定で確定する(): void
    {
        $result = $this->consensus->merge([
            $this->opinion(VerdictPerspective::Base, [$this->row(0, 'contradict')]),
            $this->opinion(VerdictPerspective::Official, [$this->row(0, 'contradict')]),
        ]);

        $this->assertSame('contradict', $result['verdicts'][0]['verdict']);
        $this->assertTrue($result['verdicts'][0]['consensus']['is_unanimous']);
    }

    public function test_1人しか判定していない主張は判定割れではなく検証不能にする(): void
    {
        $result = $this->consensus->merge([
            $this->opinion(VerdictPerspective::Base, [$this->row(0, 'match')]),
            $this->opinion(VerdictPerspective::Official, []),
            $this->opinion(VerdictPerspective::Review, []),
        ]);

        $verdict = $result['verdicts'][0];
        // 意見は割れていない（1種類しか出ていない）ので split にはしない
        $this->assertSame('unverifiable', $verdict['verdict']);
        $this->assertSame('insufficient', $verdict['consensus']['decided_by']);
        $this->assertSame(1, $verdict['consensus']['voted']);
        $this->assertCount(1, $verdict['consensus']['opinions']);
    }

    public function test_主張との対応が不明な行は合議せずそのまま残す(): void
    {
        $orphan = $this->row(0, 'contradict', '対応不明な指摘');
        $orphan['claim_index'] = null;

        $result = $this->consensus->merge([
            $this->opinion(VerdictPerspective::Base, [$this->row(0, 'match')]),
            $this->opinion(VerdictPerspective::Official, [$orphan]),
        ]);

        $extra = collect($result['verdicts'])->firstWhere('rationale', '対応不明な指摘');
        $this->assertNotNull($extra);
        // 判定は書き換えず、合議の対象外であることだけを添える
        $this->assertSame('contradict', $extra['verdict']);
        $this->assertFalse($extra['consensus']['aggregated']);
        $this->assertSame('公式データ重視', $extra['consensus']['opinions'][0]['label']);
    }

    public function test_同じ判定者が同じ主張に出した2件目も捨てない(): void
    {
        $result = $this->consensus->merge([
            $this->opinion(VerdictPerspective::Base, [
                $this->row(0, 'match', '1件目'),
                $this->row(0, 'contradict', '同じ主張への2件目'),
            ]),
        ]);

        $this->assertCount(2, $result['verdicts']);
        $extra = $result['verdicts'][1];
        $this->assertSame('同じ主張への2件目', $extra['rationale']);
        $this->assertFalse($extra['consensus']['aggregated']);
    }

    public function test_判定が1件も無ければ例外にする(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->consensus->merge([]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $verdicts
     * @return array{perspective: VerdictPerspective, output: array<string, mixed>}
     */
    private function opinion(VerdictPerspective $perspective, array $verdicts): array
    {
        return ['perspective' => $perspective, 'output' => ['verdicts' => $verdicts]];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $claimIndex, string $verdict, string $rationale = '根拠'): array
    {
        return [
            'claim' => "主張{$claimIndex}",
            'claim_index' => $claimIndex,
            'source' => '求人票',
            'checked_against' => '公式データ #1',
            'source_ids' => [1],
            'verdict' => $verdict,
            'rationale' => $rationale,
        ];
    }
}
