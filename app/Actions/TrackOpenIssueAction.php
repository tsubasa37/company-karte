<?php

namespace App\Actions;

use App\Enums\AnalysisStep;
use App\Models\AnalysisRun;
use App\Models\Company;
use App\Models\OpenIssue;
use App\Services\Ai\VerdictConsensus;
use Illuminate\Support\Carbon;

/**
 * P2 の判定行を「面接で確認する論点」として追跡対象に登録する。
 *
 * 本文は画面から受け取らず、指定されたランの P2 出力から読む
 * （追跡する論点の出どころを分析結果だけに限定するため）。
 */
class TrackOpenIssueAction
{
    /** 追跡対象にできる判定。整合（match）は確認する必要が無いので登録させない */
    private const TRACKABLE = ['contradict', VerdictConsensus::SPLIT, 'unverifiable'];

    public function execute(Company $company, AnalysisRun $run, int $verdictIndex): OpenIssue
    {
        if ($run->company_id !== $company->id) {
            throw new \InvalidArgumentException('他社の分析ランから論点を登録することはできません');
        }

        $verdicts = $run->stepFor(AnalysisStep::P2)?->output_json['verdicts'] ?? null;

        if (! is_array($verdicts)) {
            throw new \RuntimeException('このランには裏取り判定（P2）の出力がありません');
        }

        $verdict = $verdicts[$verdictIndex] ?? null;

        if (! is_array($verdict)) {
            throw new \RuntimeException("指定された判定が見つかりません（index={$verdictIndex}）");
        }

        if (! in_array($verdict['verdict'] ?? '', self::TRACKABLE, true)) {
            throw new \RuntimeException('整合と判定された主張は論点として追跡しません');
        }

        $claim = (string) ($verdict['claim'] ?? '');

        if (trim($claim) === '') {
            throw new \RuntimeException('主張の本文が空のため論点として登録できません');
        }

        // 同じ論点を二度登録しない。既にあるものは状態を変えずにそのまま返す
        // （一度解消した論点を、再分析で同じ判定が出るたびに未確認へ戻さない）
        return OpenIssue::firstOrCreate(
            [
                'company_id' => $company->id,
                'claim_hash' => OpenIssue::hashFor($claim),
            ],
            [
                'opened_run_id' => $run->id,
                'claim' => $claim,
                'verdict' => (string) $verdict['verdict'],
                'rationale' => (string) ($verdict['rationale'] ?? '') ?: null,
                'status_updated_at' => Carbon::now(),
            ],
        );
    }
}
