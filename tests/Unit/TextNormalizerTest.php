<?php

namespace Tests\Unit;

use App\Services\TextNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TextNormalizerTest extends TestCase
{
    /**
     * 突合で「同じ」と見なしたい表記ゆれの組。
     *
     * @return array<string, array{string, string}>
     */
    public static function 同一視する表記ゆれ(): array
    {
        return [
            '全角英数と半角英数' => ['ＡＢＣ123', 'abc123'],
            '半角カナと全角カナ' => ['ｶﾌﾞｼｷ', 'カブシキ'],
            '半角カナの濁点は1文字に合成' => ['ｶﾞｲｼｬ', 'ガイシャ'],
            '中黒あり/なし' => ['ジェイ・ティ', 'ジェイティ'],
            '半角中黒あり/なし' => ['ｼﾞｪｲ･ﾃｨ', 'ジェイティ'],
            '空白・改行' => ["芙 蓉　テスト\n工業", '芙蓉テスト工業'],
            '波ダッシュ' => ['500万円〜800万円', '500万円～800万円'],
            'ハイフン各種' => ['フル－リモート', 'フル—リモート'],
            '引用符' => ['「フルリモート可」', 'フルリモート可'],
            '英字の大小' => ['ABC Corp', 'abccorp'],
        ];
    }

    #[DataProvider('同一視する表記ゆれ')]
    public function test_表記ゆれは同じ照合キーになる(string $a, string $b): void
    {
        $this->assertSame(TextNormalizer::normalize($a), TextNormalizer::normalize($b));
    }

    public function test_別の文字列は同じ照合キーにならない(): void
    {
        $this->assertNotSame(
            TextNormalizer::normalize('芙蓉テスト工業'),
            TextNormalizer::normalize('芙蓉テスト商事'),
        );
    }

    public function test_全角英数は半角小文字になる(): void
    {
        $this->assertSame('abcテスト123', TextNormalizer::normalize('ＡＢＣテスト１２３'));
    }

    public function test_空文字は空文字のまま(): void
    {
        $this->assertSame('', TextNormalizer::normalize('   '));
    }
}
