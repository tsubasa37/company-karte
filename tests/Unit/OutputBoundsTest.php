<?php

namespace Tests\Unit;

use App\Services\Ai\AiOutputMismatchException;
use App\Services\Ai\OutputValidator;
use PHPUnit\Framework\TestCase;

/**
 * AI 応答の大きさの上限。スキーマの外側で、注入で読み込ませた内容の丸写しのような異常だけを弾く。
 * 正常な分析出力を削る値ではないので、ここの数字を下げるときは実測（数百字・数十件）と見比べること。
 */
class OutputBoundsTest extends TestCase
{
    public function test_通常の大きさの応答は通る(): void
    {
        $data = [
            'verdicts' => array_fill(0, 60, ['claim' => str_repeat('あ', 500), 'source_ids' => [1, 2, 3]]),
            'summary' => str_repeat('い', 3000),
        ];

        (new OutputValidator)->assertBounded($data);

        $this->addToAssertionCount(1);
    }

    public function test_長すぎる文字列は場所つきで例外になる(): void
    {
        $this->expectException(AiOutputMismatchException::class);
        $this->expectExceptionMessage('/items/0/body');

        (new OutputValidator)->assertBounded(['items' => [['body' => str_repeat('あ', 6001)]]]);
    }

    public function test_多すぎる配列は場所つきで例外になる(): void
    {
        $this->expectException(AiOutputMismatchException::class);
        $this->expectExceptionMessage('/items');

        (new OutputValidator)->assertBounded(['items' => array_fill(0, 501, 'x')]);
    }

    public function test_応答全体が大きすぎれば例外になる(): void
    {
        $this->expectException(AiOutputMismatchException::class);
        $this->expectExceptionMessage('バイト');

        // 1項目は上限内でも、合計で 300KB を超える
        (new OutputValidator)->assertBounded(['items' => array_fill(0, 200, str_repeat('a', 2000))]);
    }
}
