<?php

namespace Tests\Unit;

use App\Services\CompanyNameNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CompanyNameNormalizerTest extends TestCase
{
    /**
     * 同じ企業として名寄せしたい表記の組。
     *
     * @return array<string, array{string, string}>
     */
    public static function 同一視する社名(): array
    {
        return [
            '法人格の前後' => ['株式会社芙蓉テスト工業', '芙蓉テスト工業株式会社'],
            '法人格の省略記号' => ['芙蓉テスト工業（株）', '芙蓉テスト工業(株)'],
            '法人格なし' => ['株式会社芙蓉テスト工業', '芙蓉テスト工業'],
            '有限会社' => ['有限会社芙蓉テスト', '芙蓉テスト（有）'],
            '全角英数' => ['ＡＢＣテスト工業株式会社', 'ABCテスト工業株式会社'],
            '半角カナ' => ['ﾃｽﾃｨﾝｸﾞ工業株式会社', '株式会社テスティング工業'],
            '中黒あり/なし' => ['株式会社ジェイ・ティテスト', 'ジェイティテスト株式会社'],
            '空白' => ['株式会社 芙蓉　テスト工業', '芙蓉テスト工業株式会社'],
        ];
    }

    #[DataProvider('同一視する社名')]
    public function test_表記ゆれは同じ名寄せキーになる(string $a, string $b): void
    {
        $normalizer = new CompanyNameNormalizer;

        $this->assertSame($normalizer->normalize($a), $normalizer->normalize($b));
    }

    public function test_別法人は同じ名寄せキーにならない(): void
    {
        $normalizer = new CompanyNameNormalizer;

        $this->assertNotSame(
            $normalizer->normalize('株式会社芙蓉テスト工業'),
            $normalizer->normalize('株式会社芙蓉テスト商事'),
        );
    }

    public function test_法人格語を除いた本体だけが残る(): void
    {
        $this->assertSame('abcテスト工業', (new CompanyNameNormalizer)->normalize('ＡＢＣテスト工業株式会社'));
    }

    public function test_中黒だけの短い社名は正規化後に短くなる(): void
    {
        // 労基突合の「正規化後3文字未満は突合しない」ガードが効く長さになることを固定する
        $this->assertSame('ab', (new CompanyNameNormalizer)->normalize('株式会社A・B'));
    }
}
