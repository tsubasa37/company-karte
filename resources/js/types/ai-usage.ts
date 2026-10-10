/**
 * AI の使用状況（実行先横断）。
 * 件数・トークン・費用はすべて PHP（BuildAiUsageAction）が確定した値で、画面は単位の変換だけを行う。
 * トークン・費用の null は「取れなかった」で、0 とは区別する。
 */

/** サブスク利用枠の消費率。codex を使う全ツールの合計で、このアプリだけの数字ではない */
export interface CodexRateLimit {
    available: boolean;
    usedPercent: number | null;
    /** 枠の期間（例: 7日） */
    windowLabel: string | null;
    resetsAt: string | null;
    planType: string | null;
    /** この数値を記録した時点（= 最後に codex が動いた時刻） */
    observedAt: string | null;
    /** 読めなかった理由。読めていれば null（推測値では埋めない） */
    unavailableReason: string | null;
}

/** 実行先1つぶんの集計。0 件の実行先も行として届く */
export interface AiProviderUsage {
    provider: string;
    label: string;
    /** サブプロセス実行（codex）。API 課金ではなくサブスクの枠を消費する */
    isSubprocess: boolean;
    calls: number;
    failed: number;
    autoCalls: number;
    inputTokens: number | null;
    cachedTokens: number | null;
    outputTokens: number | null;
    /** 出力の内数としての思考トークン */
    reasoningTokens: number | null;
    /** 推定費用（マイクロUSD）。費用のある実行が1件も無ければ null */
    costMicroUsd: number | null;
    /** トークンは取れたが単価表に無く、費用を出せなかった実行 */
    unpricedCalls: number;
    /** 実行先がトークンを返さなかった実行 */
    untokenedCalls: number;
    /** サブスク実行の参考換算（API なら幾らだったか。マイクロUSD）。請求は発生しない。API 系は null */
    referenceCostMicroUsd: number | null;
    /** 参考換算のうち、同名のモデルが API の単価表に無く換算できなかった実行。API 系は null */
    referenceUnpricedCalls: number | null;
    /** codex の利用枠。API 系は null */
    rateLimit: CodexRateLimit | null;
    lastAt: string | null;
}

export interface AiPurposeUsage {
    provider: string;
    providerLabel: string;
    /** サブプロセス実行（codex）。費用 null の意味が API 系と違う（無いのが正常 / 出せなかった） */
    isSubprocess: boolean;
    purpose: string;
    label: string;
    /** ボタンを押して明示的に走らせたものか（false は保存・定期取得に付随する自動実行） */
    isExplicit: boolean;
    calls: number;
    failed: number;
    costMicroUsd: number | null;
}

export interface AiCompanyUsage {
    /** 削除済み企業の合計バケツは null（外部キーの nullOnDelete で企業に紐づけられないため） */
    id: number | null;
    name: string;
    calls: number;
}

export interface AiModelUsage {
    provider: string;
    providerLabel: string;
    /** サブプロセス実行（codex）。費用 null の意味が API 系と違う（無いのが正常 / 出せなかった） */
    isSubprocess: boolean;
    /** null は実行先の既定モデルに任せた実行 */
    model: string | null;
    calls: number;
    costMicroUsd: number | null;
}

export interface AiUsageData {
    /** 集計期間（日） */
    days: number;
    calls: number;
    autoCalls: number;
    /** 自動実行分の推定費用。null は「出せなかった」か「課金される実行が無かった」で、理由は下の2件数で分かる */
    autoCostMicroUsd: number | null;
    /** 自動実行のうち、トークンは取れたが単価表に無く費用を出せなかった件数（API のみ） */
    autoUnpricedCalls: number;
    /** 自動実行のうち、実行先がトークンを返さず費用を出せなかった件数（API のみ） */
    autoUntokenedCalls: number;
    providers: AiProviderUsage[];
    byPurpose: AiPurposeUsage[];
    byCompany: AiCompanyUsage[];
    byModel: AiModelUsage[];
    lastAt: string | null;
}
