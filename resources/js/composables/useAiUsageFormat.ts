/**
 * 使用状況の表示整形。合計・費用の計算は PHP が済ませてあり、ここが行うのは単位の変換だけ。
 */

/** 表示できる最小額。これ未満の正の値は 4 桁でも $0.0000 になり 0 と見分けが付かない */
const SMALLEST = 0.0001;

/** マイクロUSD → $ 表記。1セント未満は $0.00 に丸めず4桁で出す（実費があるのに 0 に見せない） */
function toUsd(micro: number): string {
    const usd = micro / 1_000_000;

    if (usd > 0 && usd < SMALLEST) return `<$${SMALLEST.toFixed(4)}`;

    return `$${usd.toFixed(usd > 0 && usd < 0.01 ? 4 : 2)}`;
}

export function useAiUsageFormat() {
    return {
        /** 費用。null は「出せなかった」なので 0 を作らず、理由に応じた文言を呼び出し側から渡す */
        cost: (micro: number | null, fallback = '—'): string =>
            micro === null ? fallback : toUsd(micro),
        /** トークン数。桁が大きいので k / M に丸める。null は未取得 */
        tokens: (value: number | null): string => {
            if (value === null) return '—';
            if (value >= 1_000_000) return `${(value / 1_000_000).toFixed(1)}M`;
            if (value >= 10_000) return `${Math.round(value / 1_000)}k`;

            return value.toLocaleString();
        },
    };
}
