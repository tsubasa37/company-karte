<?php

namespace App\Actions;

use App\Enums\SourceType;
use App\Jobs\ExtractConditionsJob;
use App\Jobs\SplitReviewSourceJob;
use App\Models\Company;
use App\Models\SourceDocument;
use App\Services\SourceRevisionRecorder;
use Illuminate\Validation\ValidationException;

/**
 * 既存ソースの「種別の付け替え」「企業の付け替え」「面接メモ本文の編集」。
 *
 * 種別・企業が変わると派生データ（労働条件 conditions_json / 分割済み口コミ review_items）の
 * 前提が崩れるため、変更前と変更後の両方について作り直す。
 *
 * 本文は再クリーニングしない（種別を面接メモに/から変えても取り込み時の状態を保つ）。
 * クリーニングは取り込み時の1回だけという扱いにしないと、後から掛けた分が
 * 「誰も指示していない本文改変」になり、原本と分析材料の対応が追えなくなるため。
 * removed_lines も取り込み時の記録なので触らない。
 */
class UpdateSourceDocumentAction
{
    public function __construct(private readonly SourceRevisionRecorder $revisions) {}

    /**
     * @param  array{type: string, company_id: int|string, raw_text?: string|null}  $attributes
     */
    public function execute(SourceDocument $source, array $attributes): SourceDocument
    {
        $newType = SourceType::from($attributes['type']);
        $newCompany = Company::findOrFail((int) $attributes['company_id']);
        $oldType = $source->type;
        $oldCompany = $source->company;

        $typeChanged = $newType !== $oldType;
        $companyChanged = $newCompany->id !== $oldCompany->id;

        $this->guardAgainstDuplicate($source, $newCompany, $newType, $companyChanged);
        $this->applyBodyEdit($source, $oldType, $attributes['raw_text'] ?? null);

        if ($typeChanged || $companyChanged) {
            $source->company_id = $newCompany->id;
            $source->type = $newType;
            // 口コミとして分割済みでも、種別・企業が変われば分割し直しが必要
            $source->split_at = null;
        }

        $source->save();

        if ($typeChanged || $companyChanged) {
            // 分割済み口コミは (company_id, source_document_id) に紐づくため作り直す
            $source->reviewItems()->delete();
            $this->rebuildDerived($oldCompany, $oldType);
            $this->rebuildDerived($newCompany, $newType);
        }

        return $source;
    }

    /**
     * 変更先に同じ (url, 種別) のソースが既にあると、次の再取得でどちらが上書きされるか
     * 決まらない。黙って統合・上書きせずエラーで止める（正規の経路は1本にする）。
     */
    private function guardAgainstDuplicate(
        SourceDocument $source,
        Company $company,
        SourceType $type,
        bool $companyChanged,
    ): void {
        if ($source->url === null) {
            return; // URL の無いソース（貼り付け）は同一性の判定ができないので衝突もしない
        }

        $exists = $company->sourceDocuments()
            ->where('url', $source->url)
            ->where('type', $type->value)
            ->whereKeyNot($source->getKey())
            ->exists();

        if (! $exists) {
            return;
        }

        throw ValidationException::withMessages([
            $companyChanged ? 'company_id' : 'type' => "同じURLの「{$type->label()}」が {$company->name} に既にあります。"
                .'どちらかを削除してから変更してください。',
        ]);
    }

    /**
     * 面接メモ本文の編集。追記専用という意図（過去の記録を失わない）は
     * 「編集前の本文を必ず履歴へ退避する」ことで担保する。
     * 面接メモ以外は取り込んだ原文なので編集させない（原文と分析材料の食い違いを作らない）。
     */
    private function applyBodyEdit(SourceDocument $source, SourceType $currentType, ?string $rawText): void
    {
        if ($rawText === null) {
            return;
        }

        if ($currentType !== SourceType::Interview) {
            throw ValidationException::withMessages([
                'raw_text' => '本文を編集できるのは面接メモだけです（取り込んだ原文は書き換えません）。',
            ]);
        }

        $this->revisions->record($source, $rawText);
        $source->raw_text = $rawText;
    }

    /**
     * 種別ごとの派生データを作り直す（DeleteSourceDocumentAction の後始末と同じ規則）。
     * 求人票系はその企業の労働条件を再抽出。口コミは、複数ページに重複掲載された口コミが
     * 「最初に分割したソース」にだけ紐づくため、その企業の口コミソースを全て分割し直す。
     */
    private function rebuildDerived(Company $company, SourceType $type): void
    {
        if (in_array($type, [SourceType::JobPosting, SourceType::Other], true)) {
            ExtractConditionsJob::dispatch($company->id);
        }

        if ($type !== SourceType::Review) {
            return;
        }

        $reviews = $company->sourceDocuments()
            ->where('type', SourceType::Review->value)
            ->pluck('id');

        $company->sourceDocuments()
            ->where('type', SourceType::Review->value)
            ->update(['split_at' => null]);

        foreach ($reviews as $id) {
            SplitReviewSourceJob::dispatch($id);
        }
    }
}
