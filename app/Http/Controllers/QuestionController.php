<?php

namespace App\Http\Controllers;

use App\Enums\AnalysisStep;
use App\Enums\RunStatus;
use App\Enums\StepStatus;
use App\Jobs\GenerateQuestionsJob;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;

/**
 * 面接での逆質問（P6）を単独で生成する。
 * 分析（P1〜P5）からは外してあり、面接が決まってから明示的に押す。
 */
class QuestionController extends Controller
{
    public function store(Company $company): RedirectResponse
    {
        if ($error = $this->blockedReason($company)) {
            return back()->withErrors(['questions' => $error]);
        }

        $run = $company->analysisRuns()->where('status', RunStatus::Done)->latest('id')->firstOrFail();

        // dispatch と同時に Pending を立てる（キュー待機中も「生成中」と表示し、連打での二重実行を防ぐ）
        $run->steps()->updateOrCreate(
            ['step' => AnalysisStep::P6->value],
            ['status' => StepStatus::Pending, 'error' => null],
        );
        GenerateQuestionsJob::dispatch($run->id);

        return back();
    }

    /** 生成できない理由（できるなら null）。押した後に失敗させず、押す前に弾く */
    private function blockedReason(Company $company): ?string
    {
        // 分析実行中に生成すると、完了と同時に表示対象ランが切り替わり生成結果が見えなくなる
        if ($company->analysisRuns()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->exists()) {
            return '分析の実行中は逆質問を生成できません。分析の完了後に生成してください。';
        }

        $run = $company->analysisRuns()->where('status', RunStatus::Done)->latest('id')->first();
        if ($run === null) {
            return '逆質問は分析（カルテ）の完了後に生成できます。';
        }

        // 逆質問は P2（主張検証）の結果を質問に変換するため、判定が無ければ codex を呼んでも空になる
        $verdicts = $run->stepFor(AnalysisStep::P2)?->output_json['verdicts'] ?? [];
        if ($verdicts === []) {
            return 'このカルテには主張検証（P2）の結果が無いため生成できません。求人票を投入して再分析してください。';
        }

        // Pending も「生成中」。キュー待機中の連打で codex が重複実行されるのを防ぐ
        $p6 = $run->stepFor(AnalysisStep::P6)?->status;
        if (in_array($p6, [StepStatus::Pending, StepStatus::Running], true)) {
            return '逆質問を生成中です。完了までお待ちください。';
        }

        return null;
    }
}
