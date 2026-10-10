<?php

namespace App\Services\Ai\Decision;

use App\Enums\AiProvider;
use App\Services\Ai\Driver\TokenUsage;

/**
 * ドライバが返す判定 1 回分の応答。回答は質問の型に対応する値オブジェクトに載せてある。
 */
final readonly class DecisionResponse
{
    /**
     * @param  array<string, ChoiceAnswer|ScoreAnswer|NoulAnswer>  $answers  質問 id => 回答
     * @param  TokenUsage|null  $tokens  取れなかったときは null（0 と区別する）
     */
    public function __construct(
        public array $answers,
        public ?TokenUsage $tokens,
        public AiProvider $provider,
        public ?string $model,
    ) {}

    public function has(string $id): bool
    {
        return isset($this->answers[$id]);
    }

    public function choice(string $id): ChoiceAnswer
    {
        return $this->answerOf($id, ChoiceAnswer::class);
    }

    public function score(string $id): ScoreAnswer
    {
        return $this->answerOf($id, ScoreAnswer::class);
    }

    public function noul(string $id): NoulAnswer
    {
        return $this->answerOf($id, NoulAnswer::class);
    }

    /**
     * @template T of ChoiceAnswer|ScoreAnswer|NoulAnswer
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function answerOf(string $id, string $class): ChoiceAnswer|ScoreAnswer|NoulAnswer
    {
        $answer = $this->answers[$id] ?? throw new \LogicException("判定の回答 {$id} がありません");

        // 呼び出し側が質問の型を取り違えたときは黙って別の型で読まない
        if (! $answer instanceof $class) {
            throw new \LogicException("判定の回答 {$id} は ".class_basename($answer).' です（'.class_basename($class).' として読めません）');
        }

        return $answer;
    }
}
