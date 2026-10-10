<?php

namespace App\Services\Ai\Decision;

/**
 * 段階で採点させる質問。levels は低い順の段階名で、回答の score は 1 始まりの位置になる。
 */
final readonly class ScoreQuestion implements Question
{
    /** 段階数の範囲（API 契約） */
    public const MIN_LEVELS = 2;

    public const MAX_LEVELS = 10;

    /**
     * @param  list<string>  $levels
     */
    public function __construct(
        public string $id,
        public string $instructions,
        public array $levels,
    ) {
        QuestionGuard::assertBasics($id, $instructions);

        $count = count($levels);
        if ($count < self::MIN_LEVELS || $count > self::MAX_LEVELS) {
            throw new \InvalidArgumentException(
                "判定の質問 {$id} の段階（levels）は ".self::MIN_LEVELS.'〜'.self::MAX_LEVELS." 個にしてください（{$count} 個）",
            );
        }

        if (! array_is_list($levels)) {
            throw new \InvalidArgumentException("判定の質問 {$id} の levels は低い順の配列にしてください");
        }

        foreach ($levels as $level) {
            if (! is_string($level) || trim($level) === '') {
                throw new \InvalidArgumentException("判定の質問 {$id} に空の段階名があります");
            }
        }

        // 確率が段階名を鍵にして返る場合に対応を取れなくなるため、重複は受けない
        if (count(array_unique($levels)) !== $count) {
            throw new \InvalidArgumentException("判定の質問 {$id} の段階名が重複しています");
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function toPayload(): array
    {
        return ['type' => 'score', 'instructions' => $this->instructions, 'levels' => $this->levels];
    }
}
