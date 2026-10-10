<?php

namespace Tests\Unit;

use App\Enums\AnalysisStep;
use App\Services\Ai\AiRunner;
use App\Services\Ai\AnalysisSchemas;
use App\Services\Ai\BriefingGenerator;
use App\Services\Ai\ClipClassifier;
use App\Services\Ai\CompanyListExtractor;
use App\Services\Ai\ConditionExtractor;
use App\Services\Ai\MailExtractor;
use App\Services\Ai\ReviewSplitter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 本アプリの構造化出力スキーマが Anthropic の制限に収まることを固定する。
 *
 * 正規スキーマは OpenAI strict 形式1本で、Anthropic の上限が一番きつい。
 * 超えると 400（Schema is too complex）で実行時に初めて分かるため、スキーマを増やす前にここで落とす。
 * 確認 2026-09-03: https://platform.claude.com/docs/en/build-with-claude/structured-outputs
 * - 省略可能な項目（required に無い property）は 1 リクエストあたり 24 まで
 * - union 型（type 配列 / anyOf）の項目は 16 まで
 * - 再帰スキーマは非対応（$ref / $defs / definitions ごと使わない）
 * - minimum / maximum / minLength / maxLength / maxItems 等の検証キーワードは非対応
 *   （minItems は 0 と 1 だけ。使っていないので一律で禁止して固定する）
 */
class AiSchemaLimitsTest extends TestCase
{
    private const MAX_OPTIONAL = 24;

    private const MAX_UNION = 16;

    /** 使うと 400 になる検証キーワード。スキーマのどこにも現れないことを固定する */
    private const UNSUPPORTED_KEYWORDS = [
        'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf',
        'minLength', 'maxLength', 'minItems', 'maxItems', 'uniqueItems',
        'minProperties', 'maxProperties', 'patternProperties', 'unevaluatedProperties',
        '$ref', '$defs', 'definitions',
    ];

    /**
     * 実行する全スキーマ。private メソッド・private 定数は Reflection で取る
     * （画面から呼べる形にするために可視性を緩めない）。
     *
     * @return array<string, array<string, mixed>>
     */
    private function schemas(): array
    {
        $analysis = new AnalysisSchemas;
        $schemas = [];

        foreach (AnalysisStep::cases() as $step) {
            // RJ（口コミの機械判定）は判定 API で動き、JSON スキーマを持たない
            if ($step === AnalysisStep::RJ) {
                continue;
            }
            $schemas['分析 '.$step->value] = $analysis->for($step);
        }

        foreach ([ClipClassifier::class, ConditionExtractor::class, ReviewSplitter::class,
            CompanyListExtractor::class, MailExtractor::class, BriefingGenerator::class] as $class) {
            $schemas[class_basename($class)] = $this->privateSchema($class);
        }

        $schemas['接続テスト'] = (new \ReflectionClass(AiRunner::class))->getConstant('PROBE_SCHEMA');

        return $schemas;
    }

    /**
     * @param  class-string  $class
     * @return array<string, mixed>
     */
    private function privateSchema(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $method = $reflection->getMethod('schema');

        // schema() は定数を並べるだけでインスタンスの状態を見ないため、依存を組まずに呼ぶ
        return $method->invoke($reflection->newInstanceWithoutConstructor());
    }

    /**
     * property を1つずつ辿り、省略可能・union 型の数を数える。
     *
     * @param  array<string, mixed>  $schema
     * @return array{optional: int, union: int}
     */
    private function tally(array $schema): array
    {
        $optional = 0;
        $union = 0;

        foreach ($schema['properties'] ?? [] as $name => $property) {
            if (! in_array($name, $schema['required'] ?? [], true)) {
                $optional++;
            }
            if (is_array($property['type'] ?? null) || array_key_exists('anyOf', $property)) {
                $union++;
            }

            $nested = $this->tally(is_array($property['items'] ?? null) ? $property['items'] : $property);
            $optional += $nested['optional'];
            $union += $nested['union'];
        }

        return ['optional' => $optional, 'union' => $union];
    }

    /**
     * スキーマのどこかに現れる鍵を全て集める（配列の items・入れ子の object も辿る）。
     *
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    private function keys(array $schema): array
    {
        $keys = [];
        foreach ($schema as $key => $value) {
            $keys[] = (string) $key;
            if (is_array($value)) {
                $keys = array_merge($keys, $this->keys($value));
            }
        }

        return $keys;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function スキーマ名(): array
    {
        // データプロバイダは静的に呼ばれるため、名前だけ渡して本体はテスト側で組む
        $names = ['分析 p1', '分析 p2', '分析 p3', '分析 p4', '分析 p5', '分析 p6',
            'ClipClassifier', 'ConditionExtractor', 'ReviewSplitter', 'CompanyListExtractor',
            'MailExtractor', 'BriefingGenerator', '接続テスト'];

        return array_combine($names, array_map(fn (string $name) => [$name], $names));
    }

    #[DataProvider('スキーマ名')]
    public function test_全スキーマがanthropicの上限に収まる(string $name): void
    {
        $schemas = $this->schemas();
        $this->assertArrayHasKey($name, $schemas, 'スキーマを足したらデータプロバイダにも足す');

        $schema = $schemas[$name];
        $counts = $this->tally($schema);

        $this->assertLessThanOrEqual(self::MAX_OPTIONAL, $counts['optional'], "{$name}: 省略可能な項目が多すぎる");
        $this->assertLessThanOrEqual(self::MAX_UNION, $counts['union'], "{$name}: union 型（nullable）の項目が多すぎる");

        foreach (self::UNSUPPORTED_KEYWORDS as $keyword) {
            $this->assertNotContains($keyword, $this->keys($schema), "{$name}: {$keyword} は Anthropic が対応していない");
        }
    }

    public function test_数えたスキーマの数が実行する全経路ぶんある(): void
    {
        // 経路を足したのにここへ足し忘れると、上限超えが実行時まで分からない
        $this->assertSame(count(self::スキーマ名()), count($this->schemas()));
    }
}
