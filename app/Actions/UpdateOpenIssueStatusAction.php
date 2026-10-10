<?php

namespace App\Actions;

use App\Enums\IssueStatus;
use App\Models\OpenIssue;
use Illuminate\Support\Carbon;

/**
 * 論点の状態を手動で変える（面接メモを介さず自分で解消・取り下げにする経路）。
 *
 * 面接メモとの照合（MatchOpenIssuesAction）が残した根拠メモと出典リンクは、
 * 状態だけを変えたときは消さない。プルダウンを1回触るだけで面接で得た一次情報が
 * 消えるのを防ぐため。手入力のメモが渡されたときだけ根拠を置き換え、出典リンクを外す
 * （手入力のメモは面接メモ由来ではないため）。
 */
class UpdateOpenIssueStatusAction
{
    /**
     * @param  bool  $noteProvided  リクエストに resolution_note が含まれていたか（null で送られた場合も true）
     */
    public function execute(OpenIssue $issue, IssueStatus $status, ?string $note, bool $noteProvided): OpenIssue
    {
        $issue->status = $status;

        if ($noteProvided) {
            $issue->resolution_note = blank($note) ? null : $note;
            $issue->resolved_source_document_id = null;
        }

        $issue->status_updated_at = Carbon::now();
        $issue->save();

        return $issue;
    }
}
