<?php

namespace App\Services;

use App\Enums\AiTier;
use App\Enums\AnalysisStep;
use App\Enums\SourceType;
use App\Models\AnalysisRun;
use App\Models\Company;
use App\Services\Ai\AiSettings;

/**
 * 分析ジョブ全体の締め切り（秒）の見積もり。
 *
 * AI 1回の上限秒（Tier ごとに今選ばれている実行先の timeout）× 実際の呼び出し回数に余裕を足す。
 * 呼び出し回数は「ステップ数 + P2 の増員ぶん（人数-1） + 未分割口コミ数」。
 * 口コミの機械判定（RJ）は判定エンジンの上限秒 ×（口コミ件数 / 同時数）で別に足す。
 * 固定値だと codex 呼び出し回数が収まらない構成（例: 180秒 × 9回）で途中 SIGKILL され、
 * 実行中の codex が孤児化して使用量も記録されないままサブスクを消費し続けるため。
 * 見積もりが retry_after に収まるかは QueueHeadroom が判定する。
 */
class AnalysisTimeoutEstimator
{
    /** ジョブ起動・DB 書き込み・口コミ分割の待ちなどの余裕（秒） */
    private const MARGIN_SECONDS = 120;

    public function __construct(private readonly AiSettings $settings) {}

    public function forCompany(Company $company, int $panelSize): int
    {
        $unsplit = $company->sourceDocuments()
            ->where('type', SourceType::Review)
            ->whereNull('split_at')
            ->count();

        return $this->forPanel($panelSize, $unsplit, $company->reviewItems()->count());
    }

    public function forRun(AnalysisRun $run): int
    {
        return $this->forCompany($run->company, $run->panel_size ?? 1);
    }

    /**
     * @param  int  $reviewItems  分割済みの口コミ件数（RJ の見積もり用）。未分割ソースの口コミは件数が分からないので数えない
     */
    public function forPanel(int $panelSize, int $unsplitReviews = 0, int $reviewItems = 0): int
    {
        // P2 の増員ぶんを足す（人数1なら従来どおりステップ数と同じ）。
        // RJ は生成を呼ばないので分析 Tier の上限秒では見積もらない（下の reviewJudgmentSeconds で足す）
        $steps = array_filter(AnalysisStep::pipeline(), fn (AnalysisStep $step) => $step !== AnalysisStep::RJ);
        $calls = count($steps) + max(0, $panelSize - 1);

        // 分析ステップは分析・要約 Tier、口コミ分割は抽出・判定 Tier の上限秒で見積もる
        return $this->settings->timeoutFor(AiTier::Analysis) * $calls
            + $this->settings->timeoutFor(AiTier::Light) * $unsplitReviews
            + $this->reviewJudgmentSeconds($reviewItems)
            + self::MARGIN_SECONDS;
    }

    /**
     * RJ の見積もり。口コミは同時数ずつ並列に送り、区切りの間は直列なので「区切りの数 × 判定 1 回の上限秒」。
     * 判定エンジンが未設定なら RJ は動かないので 0。
     */
    private function reviewJudgmentSeconds(int $reviewItems): int
    {
        $timeout = $this->settings->decisionTimeout();

        if ($timeout === 0 || $reviewItems === 0) {
            return 0;
        }

        $concurrency = config('ai.decision.concurrency');

        if (! is_numeric($concurrency) || (int) $concurrency <= 0) {
            throw new \RuntimeException('config/ai.php の decision.concurrency が設定されていません');
        }

        return (int) ceil($reviewItems / (int) $concurrency) * $timeout;
    }
}
