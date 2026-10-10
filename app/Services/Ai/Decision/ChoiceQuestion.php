<?php

namespace App\Services\Ai\Decision;

/**
 * 候補から 1 つ選ばせる質問。criteria は「候補名 => 選ぶ基準の説明」。
 */
final readonly class ChoiceQuestion implements Question
{
    /**
     * @param  array<string, string>  $criteria  2 個以上（API 契約）
     */
    public function __construct(
        public string $id,
        public string $instructions,
        public array $criteria,
    ) {
        QuestionGuard::assertBasics($id, $instructions);

        if (count($criteria) < 2) {
            throw new \InvalidArgumentException("判定の質問 {$id} の候補（criteria）は 2 個以上必要です（".count($criteria).' 個）');
        }

        // 0 からの連番の鍵（["a", "b"] 等）は JSON で配列になり、候補名の対応が失われる
        if (array_is_list($criteria)) {
            throw new \InvalidArgumentException("判定の質問 {$id} の criteria は「候補名 => 説明」の連想配列にしてください");
        }

        foreach ($criteria as $option => $description) {
            if (trim((string) $option) === '') {
                throw new \InvalidArgumentException("判定の質問 {$id} に空の候補名があります");
            }
            if (! is_string($description)) {
                throw new \InvalidArgumentException("判定の質問 {$id} の候補 {$option} の説明が文字列ではありません");
            }
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * 候補名の一覧（宣言順）。数字だけの候補名は PHP で整数の鍵になるため文字列に戻す。
     *
     * @return list<string>
     */
    public function options(): array
    {
        return array_map(strval(...), array_keys($this->criteria));
    }

    public function toPayload(): array
    {
        return ['type' => 'choice', 'instructions' => $this->instructions, 'criteria' => $this->criteria];
    }
}
