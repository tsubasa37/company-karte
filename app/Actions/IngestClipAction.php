<?php

namespace App\Actions;

use App\Enums\SourceOrigin;
use App\Models\Company;
use App\Services\Ai\ClipClassifier;
use App\Services\CompanyNameNormalizer;

/**
 * 拡張から届いたページテキストをソースとして取り込む。
 * 投入先の企業が指定されていなければ AI で判定（無ければ作成）し、
 * 指定されていれば AI の企業判定は使わない（表記ゆれで別企業に割れるのを避ける）。
 */
class IngestClipAction
{
    /**
     * 社名・タイトル・要約の長さ上限。
     * 社名と要約は AI の出力、タイトルはページ側の document.title なのでどちらも長さを信用しない。
     * 該当カラムはいずれも string(255)（IngestMailAction と同じ値で切る）。
     */
    private const MAX_STRING_LENGTH = 255;

    public function __construct(
        private readonly ClipClassifier $classifier,
        private readonly CompanyNameNormalizer $names,
        private readonly UpsertSourceDocumentAction $upsert,
    ) {}

    /**
     * @param  string|null  $forcedType  拡張のボタンで選ばれた種別（null なら AI の判定を使う）
     * @param  int|null  $companyId  拡張で選ばれた投入先企業（null なら AI が企業を判定する）
     * @return array<string, mixed>
     */
    public function execute(
        string $title,
        string $url,
        string $text,
        ?string $forcedType = null,
        ?int $companyId = null,
    ): array {
        // 企業も種別もユーザーが指定済みなら AI に聞くことが無いので codex を走らせない。
        // 種別が未指定なら判定が必要なので、企業指定があっても classify は呼ぶ（企業名は使わない）。
        $result = $companyId !== null && $forcedType !== null
            ? null
            : $this->classifier->classify($title, $url, $text, $companyId !== null);

        if ($companyId !== null) {
            $company = Company::findOrFail($companyId);
        } else {
            $rejection = $this->rejectionFor($result);
            if ($rejection !== null) {
                return $rejection;
            }

            // 法人格語・表記ゆれによる重複作成を防ぐため、正規化キーで照合する。
            $name = mb_substr(trim((string) $result['company_name']), 0, self::MAX_STRING_LENGTH);
            $company = $this->names->findExisting($name) ?? Company::create(['name' => $name]);
        }

        $type = $forcedType ?? (string) ($result['source_type'] ?? 'other');
        // AI を呼んでいない場合は要約が無い（一覧では代わりに本文冒頭の抜粋が出る）
        $summary = filled($result['summary'] ?? null)
            ? mb_substr((string) $result['summary'], 0, self::MAX_STRING_LENGTH)
            : null;

        // 上書き・履歴退避・派生ジョブ（口コミ分割/条件抽出）は画面フォームと共通の経路に任せる
        $this->upsert->execute($company, [
            'type' => $type,
            'origin' => SourceOrigin::Url->value,
            'url' => $url !== '' ? $url : null,
            // 一覧で内容を思い出せるよう、ページタイトルと AI の一行要約も保存する（空は null）
            'title' => $title !== '' ? mb_substr($title, 0, self::MAX_STRING_LENGTH) : null,
            'summary' => $summary,
            'raw_text' => $text,
            'fetched_at' => now(),
        ]);

        return [
            'ok' => true,
            'company_id' => $company->id,
            'company_name' => $company->name,
            'source_type' => $type,
            'summary' => $summary ?? '',
        ];
    }

    /**
     * AI が企業を特定できなかった場合に拡張へ返す応答（特定できていれば null）。
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|null
     */
    private function rejectionFor(array $result): ?array
    {
        if (($result['is_company_related'] ?? false) && trim((string) ($result['company_name'] ?? '')) !== '') {
            return null;
        }

        // 複数企業が並ぶページは「一括登録」に案内する（拡張側がこのフラグでUIを切り替える）
        if ($result['is_multi_company'] ?? false) {
            return [
                'ok' => false,
                'reason' => 'list_page',
                'message' => '複数の企業が並ぶページのようです。企業だけをまとめて登録できます。',
            ];
        }

        return [
            'ok' => false,
            'reason' => 'not_company',
            'message' => '企業を特定できませんでした: '.($result['summary'] ?? ''),
        ];
    }
}
