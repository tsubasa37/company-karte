<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Profile;

/**
 * 分析を開始してよいかの判定。開始できない理由（画面に出す文言）を返し、
 * 問題なければ null を返す。
 */
class AnalysisGuard
{
    public function __construct(
        private readonly AnalysisTimeoutEstimator $estimator,
        private readonly QueueHeadroom $headroom,
    ) {}

    /**
     * 開始できない理由と、解消に向かう画面（ソースタブ / プロファイル設定 / AI設定）。
     * 画面はこれを使ってボタンを押す前から理由を出す。問題なければ null。
     *
     * @param  int  $panelSize  裏取り（P2）の人数。締め切りの見積もりに効く（画面表示は 1 で判定し、実行時は実際の人数で再判定する）
     * @return array{message: string, action: 'sources'|'settings'|'ai'|null}|null
     */
    public function blockInfo(Company $company, int $panelSize = 1): ?array
    {
        if ($company->sourceDocuments()->count() === 0) {
            return [
                'message' => '分析には最低1件のソース投入（求人票など）が必要です。',
                'action' => 'sources',
            ];
        }

        // codex の並列多重起動を防ぐため、未完了 run があれば実行しない。
        if ($company->analysisRuns()->unfinished()->exists()) {
            return [
                'message' => '分析が既に実行中です。完了までお待ちください。',
                'action' => null,
            ];
        }

        // おすすめ度は必須条件を基準に判定するため、プロファイル未設定のままの分析は弾く。
        $profile = Profile::first();
        if (! $profile || empty($profile->must_json)) {
            return [
                'message' => '分析の前に設定画面で必須条件を登録してください（おすすめ度の判定基準になります）。',
                'action' => 'settings',
            ];
        }

        return $this->headroomBlock($company, $panelSize);
    }

    public function reasonToBlock(Company $company, int $panelSize = 1): ?string
    {
        return $this->blockInfo($company, $panelSize)['message'] ?? null;
    }

    /**
     * 締め切りがキューの再予約時間に収まらない構成は、同じ分析が二重に走って AI を二重に消費するため
     * 実行しない。見積もりに使う AI の実行先が壊れている（キー未設定など）場合も、押す前に理由を出す。
     *
     * @return array{message: string, action: 'ai'|null}|null
     */
    private function headroomBlock(Company $company, int $panelSize): ?array
    {
        if (! $this->headroom->isDatabaseQueue()) {
            return null;
        }

        try {
            $seconds = $this->estimator->forCompany($company, $panelSize);
        } catch (\RuntimeException $e) {
            // 実行先の設定不備は、押してから 500 にせず「なぜ押せないか」として画面に出す
            return ['message' => 'AI の実行先の設定に問題があります: '.$e->getMessage(), 'action' => 'ai'];
        }

        $shortfall = $this->headroom->shortfall($seconds, '分析ジョブ');

        return $shortfall === null ? null : ['message' => $shortfall, 'action' => null];
    }
}
