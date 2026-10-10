<?php

namespace Tests\Unit;

use App\Services\Ai\QuoteVerifier;
use PHPUnit\Framework\TestCase;

class QuoteVerifierTest extends TestCase
{
    private const SOURCE = <<<'TEXT'
        募集要項
        想定年収: 500万円〜800万円（経験・スキルを考慮）
        勤務形態: フルリモート可（月1回の出社あり）
        年間休日125日・完全週休2日制
        TEXT;

    private function filter(array $claims): array
    {
        return (new QuoteVerifier)->filter(['claims' => $claims], self::SOURCE);
    }

    public function test_原文にある引用の主張は残す(): void
    {
        $result = $this->filter([
            ['claim' => '年収は500万〜800万', 'quote' => '想定年収: 500万円〜800万円', 'category' => 'salary', 'verifiable' => true],
        ]);

        $this->assertCount(1, $result['claims']);
        $this->assertSame(0, $result['dropped_claims_count']);
    }

    public function test_原文に無い引用の主張は落とす(): void
    {
        $result = $this->filter([
            ['claim' => '年収は500万〜800万', 'quote' => '想定年収: 500万円〜800万円', 'category' => 'salary', 'verifiable' => true],
            ['claim' => 'ストックオプションあり', 'quote' => 'ストックオプション制度を全社員に付与', 'category' => 'other', 'verifiable' => true],
        ]);

        $this->assertCount(1, $result['claims'], '架空の引用を持つ主張は除外される');
        $this->assertSame(1, $result['dropped_claims_count']);
        $this->assertSame('ストックオプションあり', $result['dropped_claims'][0]['claim']);
    }

    public function test_空白や記号の違いでは落とさない(): void
    {
        $result = $this->filter([
            // 全角チルダ・空白・改行の入り方が原文と違うケース
            ['claim' => '年収レンジ', 'quote' => "想定年収 : 500万円 ～ 800万円\n", 'category' => 'salary', 'verifiable' => true],
            ['claim' => 'リモート可', 'quote' => '「フルリモート可」', 'category' => 'remote', 'verifiable' => true],
        ]);

        $this->assertCount(2, $result['claims'], '整形の差では除外しない');
        $this->assertSame(0, $result['dropped_claims_count']);
    }

    public function test_短すぎる引用は判定を保留して残す(): void
    {
        $result = $this->filter([
            ['claim' => '休日について', 'quote' => '休日', 'category' => 'holiday', 'verifiable' => true],
        ]);

        $this->assertCount(1, $result['claims']);
    }

    public function test_主張が空でも壊れない(): void
    {
        $result = (new QuoteVerifier)->filter(['claims' => []], self::SOURCE);

        $this->assertSame([], $result['claims']);
        $this->assertSame(0, $result['dropped_claims_count']);
    }

    /**
     * @param  array<int, array<string, string>>  $flags
     * @return array<string, mixed>
     */
    private function filterRhetoric(array $flags): array
    {
        return (new QuoteVerifier)->filterRhetoric(['rhetoric_flags' => $flags], self::SOURCE);
    }

    public function test_p3の危険フレーズは原文引用があれば残す(): void
    {
        $result = $this->filterRhetoric([
            ['phrase' => 'フルリモート可', 'quote' => 'フルリモート可（月1回の出社あり）', 'concern' => '実態は出社あり'],
        ]);

        $this->assertCount(1, $result['rhetoric_flags']);
        $this->assertSame(0, $result['dropped_flags_count']);
    }

    public function test_p3の危険フレーズは原文に無い引用を落とす(): void
    {
        $result = $this->filterRhetoric([
            ['phrase' => 'フルリモート可', 'quote' => 'フルリモート可（月1回の出社あり）', 'concern' => '実態は出社あり'],
            ['phrase' => 'アットホームな職場', 'quote' => 'アットホームな職場で家族のように働けます', 'concern' => '公私の境界が曖昧'],
        ]);

        $this->assertCount(1, $result['rhetoric_flags'], '架空の引用の検出は除外される');
        $this->assertSame('フルリモート可', $result['rhetoric_flags'][0]['phrase']);
        $this->assertSame(1, $result['dropped_flags_count']);
    }

    public function test_p3の危険フレーズは整形の差では落とさない(): void
    {
        $result = $this->filterRhetoric([
            // 全角チルダ・空白の入り方が原文と違うケース
            ['phrase' => '想定年収の幅', 'quote' => "想定年収 : 500万円 ～ 800万円\n", 'concern' => '下限しか出ない可能性'],
        ]);

        $this->assertCount(1, $result['rhetoric_flags']);
        $this->assertSame(0, $result['dropped_flags_count']);
    }

    public function test_p3の危険フレーズが空でも壊れない(): void
    {
        $result = $this->filterRhetoric([]);

        $this->assertSame([], $result['rhetoric_flags']);
        $this->assertSame(0, $result['dropped_flags_count']);
    }

    public function test_p3の危険フレーズを落としても他のキーは保持する(): void
    {
        $result = (new QuoteVerifier)->filterRhetoric([
            'rhetoric_flags' => [['phrase' => '幹部候補', 'quote' => '幹部候補として採用します', 'concern' => '丸投げの懸念']],
            'concreteness' => ['level' => 'mid', 'reason' => '数字はあるが実績は薄い'],
        ], self::SOURCE);

        $this->assertSame([], $result['rhetoric_flags']);
        $this->assertSame(1, $result['dropped_flags_count']);
        $this->assertSame('mid', $result['concreteness']['level']);
    }
}
