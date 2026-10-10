<?php

namespace App\Services;

use App\Enums\RunStatus;
use App\Enums\SourceType;
use App\Models\Company;

/**
 * 生成済みブリーフィングが、その後のカルテ更新・面接記録追加に追いついているかの判定。
 *
 * 生成日時だけを出すと、再分析や面接記録の追加があっても古い内容が最新に見える。
 * 比較（日時の大小）はここで確定させ、画面と Markdown は返ってきた文言を出すだけにする。
 */
class BriefingStaleness
{
    /**
     * 陳腐化の理由（追いついていれば null）。
     *
     * 未生成（briefing_generated_at が null）も null を返す。まだ何も無いのは「古い」ではなく
     * 「未生成」で、画面側は生成ボタンの文言で区別しているため、ここで警告は出さない。
     */
    public function reasonFor(Company $company): ?string
    {
        $generatedAt = $company->briefing_generated_at;

        if ($generatedAt === null) {
            return null;
        }

        $analyzedAt = $company->analysisRuns()
            ->where('status', RunStatus::Done)
            ->latest('id')
            ->first()
            ?->finished_at;

        $interviewAt = $company->sourceDocuments()
            ->where('type', SourceType::Interview)
            ->latest('updated_at')
            ->first()
            ?->updated_at;

        $reasons = [];

        if ($analyzedAt !== null && $analyzedAt->gt($generatedAt)) {
            $reasons[] = 'カルテが再分析で更新されています（分析: '.$analyzedAt->format('Y-m-d H:i').'）';
        }

        // 面接記録は「ブリーフィングより新しいか」ではなく「カルテに反映済みか」で見る。
        // 反映前なら、ブリーフィングを作り直す前に再分析が必要（順番が逆だとカルテが古いまま）。
        $interviewUnanalyzed = $interviewAt !== null
            && ($analyzedAt === null || $interviewAt->gt($analyzedAt));

        if ($interviewUnanalyzed) {
            $reasons[] = '面接記録がまだ分析に反映されていません（面接記録: '.$interviewAt->format('Y-m-d H:i').'）';
        }

        if ($reasons === []) {
            return null;
        }

        $action = $interviewUnanalyzed
            ? '先に再分析 → ブリーフィング再生成の順で更新してください。'
            : 'ブリーフィングを再生成してください。';

        return implode(' / ', $reasons).'。'.$action;
    }
}
