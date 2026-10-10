<?php

namespace App\Services\Ai\Decision;

/**
 * 真偽の度合い（0〜1）を答えさせる質問。
 */
final readonly class NoulQuestion implements Question
{
    public function __construct(
        public string $id,
        public string $instructions,
    ) {
        QuestionGuard::assertBasics($id, $instructions);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function toPayload(): array
    {
        return ['type' => 'noul', 'instructions' => $this->instructions];
    }
}
