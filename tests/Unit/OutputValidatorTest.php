<?php

namespace Tests\Unit;

use App\Services\Ai\AiOutputMismatchException;
use App\Services\Ai\OutputValidator;
use Tests\TestCase;

/**
 * AI の応答を正規スキーマで検証する。
 * 「欠けた項目・違う型・余分な項目を黙って通さない」ことと、「どこが違うか」が文言に出ることが要点。
 */
class OutputValidatorTest extends TestCase
{
    /** 実際の抽出スキーマと同じ作り（object / array / nullable / enum） */
    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['reviews', 'salary_min', 'note'],
        'properties' => [
            'reviews' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['body', 'stance'],
                    'properties' => [
                        'body' => ['type' => 'string'],
                        'stance' => ['type' => 'string', 'enum' => ['現職', '退職', '不明']],
                    ],
                ],
            ],
            'salary_min' => ['type' => ['integer', 'null']],
            'note' => ['type' => 'string'],
        ],
    ];

    private function assertMismatch(array $data, string ...$fragments): void
    {
        try {
            (new OutputValidator)->assert($data, self::SCHEMA);
            $this->fail('スキーマ不一致は例外になるはず');
        } catch (AiOutputMismatchException $e) {
            foreach ($fragments as $fragment) {
                $this->assertStringContainsString($fragment, $e->getMessage());
            }
        }
    }

    public function test_スキーマどおりの応答は通る(): void
    {
        (new OutputValidator)->assert([
            'reviews' => [['body' => '本文', 'stance' => '現職']],
            'salary_min' => 500,
            'note' => '',
        ], self::SCHEMA);

        // nullable は null でも通る。空の配列も通る
        (new OutputValidator)->assert(['reviews' => [], 'salary_min' => null, 'note' => 'x'], self::SCHEMA);

        $this->assertTrue(true);
    }

    public function test_必須項目の欠落は項目名つきで例外になる(): void
    {
        $this->assertMismatch(['reviews' => [], 'salary_min' => null], '必須項目 note が欠けています');
    }

    public function test_型の不一致は場所と期待する型つきで例外になる(): void
    {
        $this->assertMismatch(
            ['reviews' => [], 'salary_min' => '500万', 'note' => ''],
            '/salary_min',
            'integer|null',
            'string',
        );
    }

    public function test_余分な項目は例外になる(): void
    {
        $this->assertMismatch(
            ['reviews' => [], 'salary_min' => null, 'note' => '', 'extra' => 1],
            'スキーマに無い項目 extra',
        );
    }

    public function test_enumに無い値は入れ子の場所つきで例外になる(): void
    {
        $this->assertMismatch(
            ['reviews' => [['body' => '本文', 'stance' => '元社員']], 'salary_min' => null, 'note' => ''],
            '/reviews/0/stance',
            'enum に無い値',
            '元社員',
        );
    }

    public function test_整数の項目に小数は通らない(): void
    {
        $this->assertMismatch(['reviews' => [], 'salary_min' => 500.5, 'note' => ''], '/salary_min');
    }
}
