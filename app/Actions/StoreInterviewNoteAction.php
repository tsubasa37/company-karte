<?php

namespace App\Actions;

use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Models\Company;
use App\Models\SourceDocument;

/**
 * 面接記録（1面接=1レコード）を定型テキストに整形し、面接メモソースとして保存する。
 * 保存経路は画面フォーム・拡張と同じ UpsertSourceDocumentAction の1本に寄せる。
 * URL を持たないため常に新規作成（= 追記専用。過去の面接記録は書き換えない）。
 */
class StoreInterviewNoteAction
{
    public function __construct(private readonly UpsertSourceDocumentAction $upsert) {}

    /**
     * @param  array<string, mixed>  $data  interviewed_on/round/interviewer/asked/answers/impressions
     */
    public function execute(Company $company, array $data): SourceDocument
    {
        return $this->upsert->execute($company, [
            'type' => SourceType::Interview->value,
            'origin' => SourceOrigin::Paste->value,
            'url' => null,
            'raw_text' => $this->format($data),
        ]);
    }

    /**
     * P2 が「面接での回答（一次情報）」として読む前提の定型フォーマット。
     *
     * @param  array<string, mixed>  $data
     */
    private function format(array $data): string
    {
        $lines = [
            "【面接記録】{$data['round']}（{$data['interviewed_on']}）",
        ];
        if (filled($data['interviewer'] ?? null)) {
            $lines[] = "面接官: {$data['interviewer']}";
        }
        if (filled($data['asked'] ?? null)) {
            $lines[] = '■ 聞いたこと';
            $lines[] = trim((string) $data['asked']);
        }
        $lines[] = '■ 回答・聞けたこと';
        $lines[] = trim((string) $data['answers']);
        if (filled($data['impressions'] ?? null)) {
            $lines[] = '■ 所感';
            $lines[] = trim((string) $data['impressions']);
        }

        return implode("\n", $lines);
    }
}
