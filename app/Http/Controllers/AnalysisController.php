<?php

namespace App\Http\Controllers;

use App\Actions\AbortAnalysisAction;
use App\Actions\RetryAnalysisAction;
use App\Actions\RunAnalysisAction;
use App\Exceptions\AnalysisBlockedException;
use App\Http\Requests\StoreAnalysisRequest;
use App\Models\AnalysisRun;
use App\Models\Company;
use App\Services\AnalysisGuard;
use Illuminate\Http\RedirectResponse;

class AnalysisController extends Controller
{
    public function store(
        Company $company,
        StoreAnalysisRequest $request,
        RunAnalysisAction $action,
        AnalysisGuard $guard,
    ): RedirectResponse {
        $reason = $guard->reasonToBlock($company, $request->panelSize());
        if ($reason !== null) {
            return $this->toCompany($company)->withErrors(['analyze' => $reason]);
        }

        try {
            $action->execute($company, $request->panelSize());
        } catch (AnalysisBlockedException $e) {
            return $this->toCompany($company)->withErrors(['analyze' => $e->getMessage()]);
        }

        return $this->toCompany($company);
    }

    /**
     * 分析の後は必ずその企業のページに戻す。
     *
     * back() は Referer 頼みで、無いとセッションに残る「最後のフル読み込み URL」に落ちる。
     * Inertia の画面遷移はその URL を更新しないため、設定画面を最後にリロードしていると
     * 分析を押しただけで設定画面へ飛ばされる（実行先の切り替え直後に起きやすい）。
     */
    private function toCompany(Company $company): RedirectResponse
    {
        return redirect()->route('companies.show', $company);
    }

    /** 失敗した分析ランを「続きから再開」する（完了済みステップは活かす） */
    public function retry(Company $company, AnalysisRun $run, RetryAnalysisAction $action): RedirectResponse
    {
        abort_unless($run->company_id === $company->id, 404);

        try {
            $action->execute($run);
        } catch (AnalysisBlockedException $e) {
            return $this->toCompany($company)->withErrors(['analyze' => $e->getMessage()]);
        }

        return $this->toCompany($company);
    }

    /** 応答が途絶えたランを手動で中断する（中断後は「失敗したステップから再開」で続きから実行できる） */
    public function abort(Company $company, AnalysisRun $run, AbortAnalysisAction $action): RedirectResponse
    {
        abort_unless($run->company_id === $company->id, 404);

        try {
            $action->execute($run);
        } catch (AnalysisBlockedException $e) {
            return $this->toCompany($company)->withErrors(['analyze' => $e->getMessage()]);
        }

        return $this->toCompany($company);
    }
}
