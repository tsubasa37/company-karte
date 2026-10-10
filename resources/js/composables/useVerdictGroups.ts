import type { P2Verdicts, VerdictCounts } from '@/types/karte';
import { computed, type ComputedRef } from 'vue';

type Verdicts = P2Verdicts['verdicts'];

/**
 * 主張検証テーブルの表示グループ分け。
 *
 * 並び順は PHP（BuildCompanyPageAction）で確定済みで、ここでは表示の枠に振り分けるだけ。
 * 二軸で分かれる: 判定（矛盾・整合・判定割れ / 検証不能）と、重要度（通常 / 低）。
 */
export function useVerdictGroups(
    p2: ComputedRef<P2Verdicts | null>,
    counts: ComputedRef<VerdictCounts>,
) {
    // 重要度 low は「設立年・資本金・所在地・法定義務の制度」など、確認しても応募判断が変わらないもの。
    // 件数が多く本当に見るべき主張を埋めるため、判定を問わず別枠に畳む（データは消さない）。
    // 旧ランは importance が undefined なので、この分岐に入らず従来どおり表示される
    const isLow = (v: { importance?: string | null }) => v.importance === 'low';

    const rated = computed<Verdicts>(() => (p2.value?.verdicts ?? []).filter((v) => !isLow(v)));

    // 判定割れは材料を足しても解消しない（面接で聞くしかない）ため、
    // 検証不能の折りたたみに入れず 矛盾/整合 と同じ常時表示に置く
    const attention = computed<Verdicts>(() => rated.value.filter((v) => v.verdict !== 'unverifiable'));
    const unverifiable = computed<Verdicts>(() => rated.value.filter((v) => v.verdict === 'unverifiable'));
    const lowImportance = computed<Verdicts>(() => (p2.value?.verdicts ?? []).filter(isLow));

    // 矛盾/判定割れ/整合が「重要度・低」枠にだけある場合、上段だけ見ると「まだありません」が虚偽になる。
    // counts（PHP集計・low込みの総数）と表示グループの差はこの件数で埋めて明示する
    const lowAttention = computed(() => lowImportance.value.filter((v) => v.verdict !== 'unverifiable').length);

    /**
     * 全件が検証不能の状態。矛盾・整合・判定割れが1件も無いと、通常の並びでは
     * 「0が2つ並ぶチップ」「否定文」「閉じた折りたたみ2本」だけが残り、表が1行も出ない。
     * この状態だけ専用表示に切り替える。
     */
    const unverifiableOnly = computed(
        () => counts.value.contradict === 0 && counts.value.match === 0
            && counts.value.split === 0 && counts.value.unverifiable > 0,
    );

    return { attention, unverifiable, lowImportance, lowAttention, unverifiableOnly };
}
