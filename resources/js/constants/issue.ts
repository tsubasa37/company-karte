import type { IssueStatus } from '@/types/issue';

/**
 * 論点の状態の見た目。文言の正は PHP（IssueStatus::label）で、ここは色の対応表。
 * 判定チップ（constants/verdict.ts）と同じ作り: 左罫2px＋薄地＋文字色。絵文字は使わない。
 */
export const ISSUE_STATUS_CLS: Record<IssueStatus, string> = {
    // まだ面接で確認していない
    open: 'border-l-2 border-check bg-check-bg text-check',
    // 面接で答えが出た
    resolved: 'border-l-2 border-match bg-match-bg text-match',
    // 聞いたが解消しなかった
    unresolved: 'border-l-2 border-conflict bg-conflict-bg text-conflict',
    // 聞いた結果、懸念が強まった
    worsened: 'border-l-2 border-conflict bg-conflict-bg text-conflict',
    // 追跡をやめた
    dismissed: 'border-l-2 border-rule-input bg-bench text-ink-3',
};
