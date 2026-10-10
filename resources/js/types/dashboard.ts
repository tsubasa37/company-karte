/**
 * ダッシュボード固有の型（BuildDashboardAction の返り値と対応させる）。
 * 既存の DashboardCompany / AlertBlock は types/company.ts にある。
 * company.ts が同時編集中のため、追加分は本ファイルに定義する。
 */
import type { DashboardCompany } from './company';

/** 再分析候補の1社（最終分析から変わった材料の件数つき。件数の確定はPHP側） */
export interface StaleCompany extends DashboardCompany {
    /** 求人票・口コミなどソースの増分 */
    newSources: number;
    /** EDINET・しょくばらぼなど公的数値の増分 */
    newMetrics: number;
    /** 分析後に削除されたソース */
    removedSources: number;
    /** 分析後に削除された公的数値 */
    removedMetrics: number;
}

/** 実行中（待機中・分析中）の分析ラン。ステップ進捗はPHPで数え済み */
export interface RunningRunAlert extends DashboardCompany {
    runId: number;
    statusLabel: string;
    /** 実行中のステップ名（開始前は null） */
    currentStepLabel: string | null;
    stepsDone: number;
    stepsTotal: number;
    startedAt: string | null;
    /** 実行中のまま更新が途絶えたか（判定は PHP の StuckRunDetector） */
    isStuck: boolean;
}
