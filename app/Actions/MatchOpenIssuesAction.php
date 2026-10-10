<?php

namespace App\Actions;

use App\Enums\IssueStatus;
use App\Models\Company;
use App\Models\SourceDocument;
use App\Services\Ai\IssueResolutionMatcher;
use App\Services\Ai\SourceMaterialText;
use Illuminate\Support\Carbon;

/**
 * 面接メモと追跡中の論点を突き合わせ、論点の状態を更新する。
 *
 * 状態の確定は PHP が行う（AI が返すのは論点の添字と解釈だけ）。
 * 面接記録に出てこなかった論点（not_mentioned）は触らない。
 */
class MatchOpenIssuesAction
{
    /** AI の outcome と保存する状態の対応。not_mentioned は状態を変えないのでここには無い */
    private const OUTCOME_STATUS = [
        'resolved' => IssueStatus::Resolved,
        'unresolved' => IssueStatus::Unresolved,
        'worsened' => IssueStatus::Worsened,
    ];

    public function __construct(
        private readonly IssueResolutionMatcher $matcher,
        private readonly SourceMaterialText $material,
    ) {}

    /**
     * @return int 状態を更新した論点の件数
     */
    public function execute(Company $company, SourceDocument $note): int
    {
        // 追跡中の論点だけを対象にする。解消済み・取り下げ済みを蒸し返さない
        $issues = $company->openIssues()
            ->pending()
            ->orderBy('id')
            ->limit(IssueResolutionMatcher::MAX_ISSUES)
            ->get();

        if ($issues->isEmpty()) {
            return 0;
        }

        $matches = $this->matcher->match($issues, $this->material->of($note), $company->id);

        $updated = 0;
        foreach ($matches as $match) {
            $issue = $issues->get($match['issue_index']);

            // 渡していない添字が返ってきたら、どの論点への判定か確定できない。黙って捨てない
            if ($issue === null) {
                throw new \RuntimeException(
                    "論点の消し込み判定が範囲外の添字を返しました（issue_index={$match['issue_index']} / 渡した件数=".$issues->count().'）'
                );
            }

            $status = self::OUTCOME_STATUS[$match['outcome']] ?? null;

            // not_mentioned（面接で触れられなかった）は状態を変えない。
            // 照合済みであることだけ記録し、次の面接メモでまた判定させる
            if ($status === null) {
                $issue->matched_at = Carbon::now();
                $issue->save();

                continue;
            }

            $issue->status = $status;
            $issue->resolution_note = $this->note($match);
            $issue->resolved_source_document_id = $note->id;
            $issue->matched_at = Carbon::now();
            $issue->status_updated_at = Carbon::now();
            $issue->save();
            $updated++;
        }

        return $updated;
    }

    /**
     * 判定の根拠（理由 + 面接記録からの引用）。どちらも空なら null にして
     * 「根拠なしで状態が変わった」ことを画面で分かるようにする。
     *
     * @param  array{outcome: string, quote: string, note: string}  $match
     */
    private function note(array $match): ?string
    {
        $lines = array_filter([
            trim($match['note']),
            trim($match['quote']) !== '' ? '「'.trim($match['quote']).'」' : '',
        ]);

        return $lines === [] ? null : implode("\n", $lines);
    }
}
