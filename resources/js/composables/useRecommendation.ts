/**
 * おすすめ度の表示。
 * 記号（◎○△―）とラベル文言は PHP の Recommendation enum が唯一の正で、
 * サーバーから label/symbol を受け取る。ここが持つのは色と、ラベルの分解だけ。
 */
const TEXT_CLASS: Record<string, string> = {
    high: 'text-match',
    mid: 'text-check',
    low: 'text-conflict',
    hold: 'text-ink-3',
};

/** 記号しか受け取らない行（ダッシュボードの企業サマリ）用に、記号から値へ戻す表 */
const SYMBOL_VALUE: Record<string, string> = { '◎': 'high', '○': 'mid', '△': 'low', '―': 'hold' };

const BADGE_CLASS: Record<string, string> = {
    high: 'bg-match-bg text-match-fg',
    mid: 'bg-check-bg text-check-fg',
    low: 'bg-conflict-bg text-conflict-fg',
    hold: 'bg-bench text-ink-2',
};

/**
 * PHP の Recommendation::label() は「◎ 前向き検討」のように記号込みで返る。
 * 記号を大きく別に出すデザインでは、label をそのまま添えると記号が二重になるので、
 * 半角空白で記号と文言に割る。空白が無い形に変わったときは全文を文言として扱い、
 * 文字を落とさない。
 */
const splitLabel = (label: string | null | undefined): { symbol: string; text: string } => {
    if (!label) return { symbol: '', text: '' };
    const i = label.indexOf(' ');

    return i > 0 ? { symbol: label.slice(0, i), text: label.slice(i + 1) } : { symbol: '', text: label };
};

export function useRecommendation() {
    return {
        textClass: (value: string | null | undefined) => (value ? (TEXT_CLASS[value] ?? '') : ''),
        /** 記号しか受け取らない行の色。未分析（記号なし）は空値と同じ薄墨にする */
        symbolClass: (symbol: string | null | undefined) =>
            symbol ? (TEXT_CLASS[SYMBOL_VALUE[symbol]] ?? '') : 'text-ink-4',
        badgeClass: (value: string | null | undefined) => (value ? (BADGE_CLASS[value] ?? '') : ''),
        splitLabel,
        /** 記号を別に出す画面用。label から記号を外した文言だけを返す */
        labelText: (label: string | null | undefined) => splitLabel(label).text,
    };
}
