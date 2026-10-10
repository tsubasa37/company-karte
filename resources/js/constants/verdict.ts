import type { Verdict } from '@/types/karte';

/**
 * 主張検証の判定の表示文言。
 * 並び順・確定は PHP（BuildCompanyPageAction::VERDICT_ORDER / VERDICT_LABEL）が正で、
 * ここは描画用の対応表。PHP 側の文言を変えたらこちらも合わせること。
 * cls は判定チップの見た目（左罫2px＋薄地＋文字色）。絵文字は使わず色で区別する。
 */
export const VERDICT_META: Record<Verdict, { label: string; cls: string }> = {
    contradict: { label: '矛盾', cls: 'border-l-2 border-conflict bg-conflict-bg text-conflict' },
    // 複数の判定者で意見が分かれたもの。材料不足（検証不能）とは色でも区別する
    split: { label: '判定割れ', cls: 'border-l-2 border-split bg-split-bg text-split' },
    match: { label: '整合', cls: 'border-l-2 border-match bg-match-bg text-match' },
    unverifiable: { label: '検証不能', cls: 'border-l-2 border-check bg-check-bg text-check' },
};

/** 判定なし（判定者がその主張に言及しなかった場合）も文言で明示する */
export const verdictLabel = (v: Verdict | null | undefined): string =>
    v ? (VERDICT_META[v]?.label ?? v) : '判定なし';
