<?php

namespace App\Services\Ai\Decision;

/**
 * 判定 1 回分の入力。state（判定の材料）と、それに対する型付きの質問の集まり。
 */
final readonly class DecisionRequest
{
    /** @var array<string, Question> */
    private array $byId;

    /**
     * @param  string|array<mixed>  $state  文字列か、JSON にできる配列
     * @param  list<Question>  $questions
     */
    public function __construct(
        public string|array $state,
        public array $questions,
    ) {
        // 空のまま送ると材料なしで判定が走る。AiRunner の空プロンプト拒否と同じ理由
        if ($state === '' || $state === [] || (is_string($state) && trim($state) === '')) {
            throw new \InvalidArgumentException('判定の state が空です');
        }

        if ($questions === []) {
            throw new \InvalidArgumentException('判定の質問が 0 件です');
        }

        $byId = [];
        foreach ($questions as $question) {
            if (! $question instanceof Question) {
                throw new \InvalidArgumentException('判定の質問は Question の配列にしてください');
            }
            if (isset($byId[$question->id()])) {
                throw new \InvalidArgumentException("判定の質問 id が重複しています（{$question->id()}）");
            }
            $byId[$question->id()] = $question;
        }

        $this->byId = $byId;
    }

    /** state の文字数（上限検査と使用量の prompt_chars 用）。配列は送る JSON の長さで数える */
    public function stateChars(): int
    {
        return is_string($this->state)
            ? mb_strlen($this->state)
            : mb_strlen(json_encode($this->state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** 質問側の文字数（id・instructions・候補名と説明・段階名の合計）。使用量の prompt_chars 用 */
    public function questionsChars(): int
    {
        $chars = 0;

        foreach ($this->questions as $question) {
            $payload = $question->toPayload();
            $chars += mb_strlen($question->id()) + mb_strlen($payload['instructions']);

            foreach ($payload['criteria'] ?? [] as $option => $description) {
                $chars += mb_strlen((string) $option) + mb_strlen($description);
            }
            foreach ($payload['levels'] ?? [] as $level) {
                $chars += mb_strlen($level);
            }
        }

        return $chars;
    }

    public function question(string $id): Question
    {
        return $this->byId[$id] ?? throw new \LogicException("判定の質問 {$id} はこのリクエストにありません");
    }

    /**
     * 送信する questions（id => 質問の形）。
     *
     * @return array<string, array<string, mixed>>
     */
    public function questionsPayload(): array
    {
        return array_map(fn (Question $question) => $question->toPayload(), $this->byId);
    }
}
