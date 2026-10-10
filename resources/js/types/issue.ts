/**
 * 面接で確認する論点・材料の充足度・公式数値の相対位置の型。
 * それぞれ PHP 側（OpenIssue / MaterialCoverage / MetricBenchmark）が確定した値をそのまま受け取る。
 * 件数・順位・不足の判定は画面では計算しない。
 */

/** 論点の状態。IssueStatus（PHP）と対応する */
export type IssueStatus = 'open' | 'resolved' | 'unresolved' | 'worsened' | 'dismissed';

export interface OpenIssue {
    id: number;
    claim: string;
    /** 追跡を始めた時点の P2 判定（contradict / split / unverifiable） */
    verdict: string;
    verdictLabel: string;
    rationale: string | null;
    status: IssueStatus;
    statusLabel: string;
    /** まだ面接で確認すべき論点か（未確認・未解消・懸念が強まった） */
    isPending: boolean;
    /** 状態を決めた根拠（面接での回答の要約 + 引用、または手入力のメモ） */
    resolutionNote: string | null;
    /** 根拠になった面接メモ。手動で状態を変えたときは null */
    resolvedSourceId: number | null;
    statusUpdatedAt: string | null;
    matchedAt: string | null;
}

export interface OpenIssues {
    items: OpenIssue[];
    pendingCount: number;
}

/** 材料の充足度の1項目 */
export interface CoverageItem {
    key: string;
    label: string;
    satisfied: boolean;
    /** 現状（「2件」「未投入」など） */
    detail: string;
    /** 何をすればよいか */
    hint: string;
    impact: 'high' | 'mid';
}

export interface Coverage {
    items: CoverageItem[];
    missing: CoverageItem[];
    missingCount: number;
    /** insufficient は求人票が無く、おすすめ度が判定保留から動かない状態 */
    level: 'sufficient' | 'partial' | 'insufficient';
}

/** 公式数値の、登録済み他社の中での位置（業界平均ではない） */
export interface Benchmark {
    /** 対応する official_metrics.id（数値として読めない項目は比較対象外なので行が無い） */
    metricId: number;
    key: string;
    unit: string | null;
    value: number;
    fiscalYear: string | null;
    /** 対象企業を含む社数 */
    population: number;
    comparable: boolean;
    /** 比較できないときの理由（comparable が true なら null） */
    reason: string | null;
    rank: number | null;
    median: number | null;
    diff: number | null;
    /** 中央値が 0 のときは割合を出せないので null */
    diffPercent: number | null;
}
