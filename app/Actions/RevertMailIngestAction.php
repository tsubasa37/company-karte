<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\MailIngest;
use Illuminate\Support\Facades\DB;

/**
 * メール取り込みが作ったものを取り消す。
 *
 * 自動登録を承認ゲートなしで行うかわりに、間違いを1操作で戻せるようにする。
 * 企業は「この取り込みが作った」ものだけ消す（既存企業を巻き込まない）。
 * 履歴レコード自体は残す（同じメールが次回の取得で再登録されないため）。
 *
 * 取り消しは「作ったものを消す」であって「本人が後から書いたものを消す」ではない。
 * 取り込みのあとに手を入れた企業（選考メモ・オファー・状態など）と、別の企業へ移された
 * ソースは消さずに残し、消せなかったものは戻り値で呼び出し元に返して画面に出す
 * （黙って一部だけ効いた取り消しにしない）。
 */
class RevertMailIngestAction
{
    public function __construct(private readonly DeleteSourceDocumentAction $deleteSource) {}

    /**
     * @return array<int, string> 取り消しきれずに残したものの説明（すべて消せた場合は空配列）
     */
    public function execute(MailIngest $ingest): array
    {
        // 予定・ソース・履歴・企業の4段を一括で成立させる。途中で落ちると「取り消しが半分だけ効いた」
        // 状態になり、参照を消した後では残りを消す手掛かりが無くなるため
        return DB::transaction(function () use ($ingest) {
            // 参照をクリアする前に退避する（update 後だと判定材料が消える）
            $company = $ingest->company;
            $createdCompany = $ingest->created_company;
            $source = $ingest->sourceDocument;

            // 企業を消してよいかの判定は、こちらの削除処理を始める前に確定させる。
            // ソース削除は派生ジョブ（条件抽出）を積み、同期実行の構成ではその場で
            // companies を更新するため、削除後に見ると常に「編集済み」に見えてしまう
            $edited = $company !== null && $this->wasEditedAfterIngest($company, $ingest);

            // 別の企業へ付け替えられたソースは、もうこの取り込みの作成物ではない。
            // 消すと無関係な企業のカルテから材料が失われるため残す
            $moved = $source !== null && $company !== null && $source->company_id !== $company->id;

            $kept = [];

            $ingest->scheduleEvent?->delete();

            // ソース削除は派生データ（口コミアイテム・労働条件）の後始末を含むため既存経路を使う
            if ($source !== null && ! $moved) {
                $this->deleteSource->execute($source);
            }

            if ($moved) {
                $kept[] = "ソースは「{$source->company->name}」へ移されていたため削除していません。";
            }

            $ingest->update([
                'schedule_event_id' => null,
                'source_document_id' => null,
                'company_id' => null,
                'created_company' => false,
                'reviewed' => true,
            ]);

            if ($company !== null && $createdCompany) {
                $kept = [...$kept, ...$this->settleCompany($company, $edited)];
            }

            return $kept;
        });
    }

    /**
     * この取り込みが作った企業を消すか残すかを決める。
     *
     * @return array<int, string> 残した場合の理由（消した場合は空配列）
     */
    private function settleCompany(Company $company, bool $edited): array
    {
        if ($edited) {
            return ["企業「{$company->name}」は取り込みのあとに編集されているため残しました"
                .'（選考メモ・オファー・状態などが入っています）。不要なら企業ページから削除してください。'];
        }

        if (! $this->isEmpty($company)) {
            return ["企業「{$company->name}」には別の材料（ソース・予定・分析・公的数値など）が積まれているため残しました。"];
        }

        $company->delete();

        return [];
    }

    /**
     * 取り込みのあとに企業そのものが編集されたか。
     *
     * companies の列（選考メモ・オファー・状態・お気に入り・名称など）はリレーションに現れず、
     * 列を増やすたびに見落とす。1件ずつ数えるのではなく更新時刻で見る。
     * 取り込み時の自動入力（募集ポジション）は履歴レコードを作る前に済むため、
     * 手を入れていない企業の updated_at は取り込み時刻を超えない。
     */
    private function wasEditedAfterIngest(Company $company, MailIngest $ingest): bool
    {
        $ingestedAt = $ingest->created_at;
        $updatedAt = $company->updated_at;

        // 時刻が欠けていると編集の有無を判定できない。判定できないものを消す側に倒さない
        if ($ingestedAt === null || $updatedAt === null) {
            return true;
        }

        return $updatedAt->gt($ingestedAt);
    }

    /**
     * 企業に何も積まれていないか。
     *
     * companies への外部キーはいずれも cascadeOnDelete のため、ここで見落とした関連は
     * 企業削除で黙って道連れになる。特に official_metrics は本人が手動で取得した
     * EDINET / gBizINFO の公的データで、取り込みの取り消しで消えてはいけない。
     */
    private function isEmpty(Company $company): bool
    {
        return $company->sourceDocuments()->count() === 0
            && $company->scheduleEvents()->count() === 0
            && $company->analysisRuns()->count() === 0
            && $company->officialMetrics()->count() === 0
            && $company->reviewItems()->count() === 0
            && $company->openIssues()->count() === 0;
    }
}
