/**
 * 分析カルテ（P1〜P6）の型。
 * app/Services/Ai/AnalysisSchemas.php が AI に渡す JSON Schema と対応させる。
 * スキーマを変えたらこちらも合わせること。
 */

import type { IssueStatus } from '@/types/issue';

export type ClaimCategory =
    | 'salary' | 'overtime' | 'holiday' | 'remote' | 'culture'
    | 'turnover' | 'growth' | 'business' | 'tech' | 'other';

/** AI（判定者）が出せる判定。AIの語彙はこの3値のみ */
export type AiVerdict = 'match' | 'contradict' | 'unverifiable';

/**
 * カルテに保存される確定判定。
 * split は「複数の判定者の意見が分かれた」ことを PHP が機械的に付ける値で、AI は出さない。
 * 材料不足（unverifiable）は材料を足せば解消するが、split は面接で確かめるしかない別物。
 */
export type Verdict = AiVerdict | 'split';
export type CheckStatus = 'met' | 'unmet' | 'unknown';
export type Priority = 'high' | 'mid' | 'low';

/** P1 主張抽出（引用が原文に無い主張はPHPが除外し、その件数を dropped_claims_count に持つ） */
export interface P1Claims {
    claims: {
        claim: string;
        quote: string;
        category: ClaimCategory;
        verifiable: boolean;
        /** 転職判断への影響度。importance 導入(2026-07)以前のランには存在しない（画面は「未保存」と明示する） */
        importance?: Priority;
    }[];
    dropped_claims: { claim: string; quote: string }[];
    dropped_claims_count: number;
}

/** 判定者1人の意見（複数人で裏取りしたときの内訳） */
export interface PanelOpinion {
    perspective: string;
    /** 観点の表示名（PHP の VerdictPerspective が確定する） */
    label: string;
    verdict: AiVerdict | null;
    rationale: string;
    /** この判定者が突き合わせた材料。観点が違えば見た材料も違うため、代表行のものとは限らない */
    checked_against: string;
    source_ids: number[];
}

/**
 * 複数人の判定をPHPが1つに確定した結果（VerdictConsensus）。
 * 人数1のランでも付く（panel_size = 1・全員一致）。多重化以前のランにはキー自体が無い。
 */
export interface VerdictConsensus {
    panel_size: number;
    /** 実際にこの主張を判定した人数（言及しなかった判定者は含まない。合議できなかった行には無い） */
    voted?: number;
    /** 判定ごとの票数（合議できなかった行には無い） */
    counts?: Record<AiVerdict, number>;
    /** 多数決で確定したときの賛成人数。割れた・支持が足りないときは null */
    agreed?: number | null;
    /** 全員が判定し、全員が同じ判定だったか */
    is_unanimous?: boolean;
    /**
     * majority: 過半数で確定 / split: 意見が分かれた（verdict も split）/
     * insufficient: 判定した人数が足りず過半数に届かなかった（verdict は unverifiable）
     */
    decided_by?: 'majority' | 'split' | 'insufficient';
    /** 合議の結果を説明する文言（PHPが確定。画面で組み立てない） */
    summary?: string;
    /** false は「どの主張への判定か特定できず合議できなかった行」 */
    aggregated: boolean;
    opinions: PanelOpinion[];
}

/** P2 裏取り判定 */
export interface P2Verdicts {
    verdicts: {
        claim: string;
        /**
         * 対応する P1 主張の添字（claims 配列の 0 始まり）。P2 の AI 出力が引用する。
         * claim_index 導入以前のランにはキー自体が無い。
         */
        claim_index?: number | null;
        /**
         * 主張の重要度（P1 の importance を claim_index で引き当てて PHP が付ける表示用の値）。
         * importance / claim_index 未保存の旧ランではキー自体が無く、引き当てに失敗した行は null。
         */
        importance?: Priority | null;
        source: string;
        checked_against: string;
        /**
         * 突き合わせた材料の source_documents.id（材料の見出し「【求人票 #12 / host】」の #ID）。
         * source_ids 導入(2026-07)以前のランには存在しない（画面は「未保存」と明示する）
         */
        source_ids?: number[];
        verdict: Verdict;
        rationale: string;
        /** 複数人で判定したときの合議結果（多重化以前のランにはキー自体が無い） */
        consensus?: VerdictConsensus;
        /**
         * 並べ替える前の（保存済み output_json の中での）添字。
         * 論点として登録するときは表示上の行番号ではなくこれを送る。
         */
        sourceIndex?: number;
        /** 既に論点として追跡している場合の open_issues.id（未追跡は null） */
        issueId?: number | null;
        /**
         * 追跡中の論点の状態（未追跡は null）。
         * テーブルから追跡を解除してよいか（未確認のものだけ）の判定に使う。
         */
        issueStatus?: IssueStatus | null;
        /**
         * 同じランの口コミの機械判定（RJ）の集計。RJ を回していないラン・対応する集計が無い行は null、
         * 機械判定の導入以前に保存されたカルテにはキー自体が無い。
         */
        machine?: MachineReviewAggregate | null;
    }[];
}

/** 口コミの機械判定の出典（review_items.id と、その口コミを取り込んだ source_documents.id） */
export interface MachineReviewItem {
    itemId: number;
    sourceId: number;
}

/**
 * 主張1件ぶんの口コミの機械判定の集計（PHP の ClaimReviewAggregate が確定。確率は含めない）。
 * flag は LLM の判定と逆向きの口コミがしきい値以上あるときの「要確認」の種別。
 */
export interface MachineReviewAggregate {
    /** 表示文言（PHPが確定。画面で組み立てない） */
    summary: string;
    counts: { total: number; contradict: number; support: number; none: number; unsure: number };
    contradictItems: MachineReviewItem[];
    supportItems: MachineReviewItem[];
    flag: 'contradict_reviews' | 'support_reviews' | null;
}

/**
 * 実質給与の確定計算（PHP の SalaryBreakdownCalculator が入れる。AIには計算させない）。
 * 計算に必要な数値が求人票に無い場合は base_salary_yen / effective_hourly_yen が null で、
 * unavailable_reason に理由が入る。
 */
export interface SalaryComputed {
    /** 実質時給の計算に使った月間所定労働時間 */
    monthly_work_hours: number;
    base_salary_yen: number | null;
    effective_hourly_yen: number | null;
    unavailable_reason: string | null;
}

/** P3 レトリック検出・給与記載の抽出（AIは抽出のみ。計算は computed） */
export interface P3Rhetoric {
    rhetoric_flags: { phrase: string; quote: string; concern: string }[];
    concreteness: { level: 'low' | 'mid' | 'high'; reason: string };
    salary_breakdown: {
        has_fixed_overtime: boolean;
        fixed_overtime_hours: number | null;
        fixed_overtime_amount_yen: number | null;
        monthly_salary_min_yen: number | null;
        monthly_salary_max_yen: number | null;
        note: string;
        /** 抽出のみへの変更(2026-07)以前のランには存在しない（画面は「未保存」と明示する） */
        computed?: SalaryComputed | null;
    };
}

/** P4 口コミ品質処理 */
export interface P4Reviews {
    material_summary: string;
    strong_points: string[];
    weak_or_extreme: string[];
    split_points: string[];
    /** 古い口コミと最近の口コミで様子が変わった点 */
    time_changes: string[];
    note: string;
    /**
     * 口コミ材料の件数（AIの作文でなくPHPが確定した値。分析ジョブが後付けする）。
     * material_meta 導入以前のランにはキー自体が無い。
     */
    material_meta?: { total: number; included: number; omitted: number };
}

export interface ConditionCheck {
    condition: string;
    status: CheckStatus;
    evidence: string;
}

/** NG条件の判定（hit=該当の可能性 / clear=該当なし / unknown=材料不足） */
export interface NgCheck {
    condition: string;
    status: 'hit' | 'clear' | 'unknown';
    evidence: string;
}

/** P5 適合評価（おすすめ度は PHP 側で確定するため p5 には含めない） */
export interface P5Summary {
    must_checks: ConditionCheck[];
    want_checks: ConditionCheck[];
    /** ng_checks 導入(2026-07)以前のランには存在しない */
    ng_checks?: NgCheck[];
    appeal: string;
    concern: string;
    summary_lines: string[];
}

/** P6 逆質問生成 */
export interface P6Questions {
    questions: {
        priority: Priority;
        question: string;
        targets: string;
        ask_to: 'hr' | 'engineer' | 'exec' | 'any';
        casual_version: string;
    }[];
}

/** おすすめ度（表示文言は PHP の Recommendation enum が正） */
export interface RecommendationView {
    value: string;
    label: string;
    symbol: string;
}

/** 判定が変わった主張1件（表示文言は PHP が確定。前回・今回で未抽出なら注記文が入る） */
export interface VerdictChange {
    claim: string;
    prevLabel: string;
    currLabel: string;
}

/** どの主張の判定が変わったか（比較できないランは items 空 + unavailableReason） */
export interface VerdictChanges {
    items: VerdictChange[];
    total: number;
    /** 表示上限を超えて省略した件数 */
    more: number;
    unavailableReason: string | null;
}

/** 前回分析との差分 */
export interface KarteDiff {
    prevRecommendation: RecommendationView | null;
    currRecommendation: RecommendationView | null;
    prev: VerdictCounts;
    curr: VerdictCounts;
    changes: VerdictChanges;
    /** 前回と裏取りの人数が違う場合の注記（同じ人数なら null） */
    panelNote: string | null;
}

/**
 * カルテの鮮度（分析が見た材料から変わった件数。判定は PHP）。
 * 判定しきれないランは unavailableReason に理由が入る。
 */
export interface KarteFreshness {
    /** 分析後に追加・更新されたソース */
    newSources: number;
    /** 分析後に追加・更新された公式数値 */
    newMetrics: number;
    /** 分析後に削除されたソース（カルテの根拠が失われている） */
    removedSources: number;
    /** 分析後に削除された公式数値 */
    removedMetrics: number;
    isStale: boolean;
    unavailableReason: string | null;
}

/** P2 判定の件数（PHPで確定集計） */
export interface VerdictCounts {
    match: number;
    contradict: number;
    /** 判定者の意見が分かれた件数（1人で分析したランは常に 0） */
    split: number;
    unverifiable: number;
}

/** 完了した分析ラン1件分のカルテ（スキップされたステップは null + skipped に理由） */
export interface Karte {
    p1: P1Claims | null;
    p2: P2Verdicts | null;
    p3: P3Rhetoric | null;
    p4: P4Reviews | null;
    p5: P5Summary | null;
    p6: P6Questions | null;
    recommendation: RecommendationView | null;
    /** なぜその判定になったか（PHPが確定した文言） */
    recommendationReason: string | null;
    /** 重要度で並べ替えられなかった理由（並べ替えできていれば null） */
    importanceNote: string | null;
    diff: KarteDiff | null;
    /** スキップされたステップの理由（step値 => 理由。例: {p4: '素材未投入'}） */
    skipped: Record<string, string>;
    verdictCounts: VerdictCounts;
    /**
     * 充足判定に添える注意書き（判定割れが無ければ null）。
     * 個別条件の ✅ はPHPで検証できないため、根拠の確認を促す文言をPHPが確定する。
     */
    checkSplitNote: string | null;
    /** P1 で抽出された主張数（引用チェックの分母） */
    claimCount: number;
    /** このランに口コミの機械判定（RJ）の行があるか（無ければ主張検証テーブルに機械判定の列を出さない） */
    hasMachineJudgment: boolean;
    /** このカルテを生成したランのID（最新ランと違うときはバナーで明示する） */
    runId: number;
    /** このカルテを何人の判定者で裏取りしたか（多重化前のランは 1） */
    panelSize: number;
    finishedAt: string | null;
    /** 分析後に材料が増えていないか（増えていれば再分析を促す） */
    freshness: KarteFreshness;
    /** 逆質問（P6）の生成状態。分析には含めず面接が決まってから個別に生成する */
    questions: QuestionsState;
}

/** 逆質問（P6）の生成状態 */
export interface QuestionsState {
    isGenerating: boolean;
    error: string | null;
    /** 一度でも実行済みか（ボタンの文言を「生成する / 再生成」で切り替える） */
    generated: boolean;
}
