import type { CompanyRow, Option } from '@/types/company';
import { router, useRemember } from '@inertiajs/vue3';
import { computed, toValue, watch, type MaybeRefOrGetter, type Ref, type WritableComputedRef } from 'vue';

/**
 * ステータスセグメントの表示順（CompanyStatus enum の定義順。表示順のみに使い、判定には使わない）。
 * ここに無い status 値が来ても末尾に出るだけで欠落はしない。
 */
const STATUS_ORDER = ['kininaru', 'casual', 'applied', 'interview', 'offer', 'declined'];

/** おすすめ度の並び順（Recommendation enum の値。良い順）。未分析(null)・未知の値は末尾 */
export const RECOMMENDATION_ORDER = ['high', 'mid', 'low', 'hold'];

export const recommendationRank = (r: string | null) => {
    const i = r === null ? -1 : RECOMMENDATION_ORDER.indexOf(r);

    return i === -1 ? RECOMMENDATION_ORDER.length : i;
};

export type CompanySort = 'recommendation' | 'updated';

/** ステータスの絞り込みセグメント。count は絞り込み前の全社での件数（「全て」の件数と足し合わせが合う） */
export type StatusOption = Option & { count: number };

/**
 * 企業一覧のフロント完結の絞り込みと並び替え（サーバ検索はしない）。
 * - 検索: 企業名・ポジションの素朴な部分一致（かな/カナ正規化はしない）
 * - ステータス: セグメントで単一選択（null = 全て）。★だけ表示は独立のトグル
 * - 並び: ★お気に入りが常に第一キー。次におすすめ度順（既定）か更新日順。
 *   同順位は更新日の新しい順（サーバの並びが安定ソートで残るため同日内も更新順）
 * 選択肢はサーバから options が渡っていないため、companies の実データから導出する。
 *
 * 絞り込みの状態は useRemember で履歴に載せる（企業ページから「戻る」で条件が消えないように）。
 * 選考ステータスと★だけ表示は URL（?status= と ?favorite=1）と対にする（ダッシュボードのボタンはこれで一覧を開く）。
 * 切り替えると URL も書き換える。書き換えないと、外したはずの絞り込みが再読み込みで URL から戻ってしまう。
 */
/** URL で指定された絞り込み。知らない選考ステータスは無視する（黙って全件が消えた一覧にしない） */
const filterFromUrl = (): { status: string | null; favoriteOnly: boolean } => {
    const params = new URLSearchParams(window.location.search);
    const status = params.get('status');

    return {
        status: status !== null && STATUS_ORDER.includes(status) ? status : null,
        favoriteOnly: params.get('favorite') === '1',
    };
};

export function useCompanyFilter(companies: MaybeRefOrGetter<CompanyRow[]>) {
    const fromUrl = filterFromUrl();
    const state = useRemember(
        {
            query: '',
            status: fromUrl.status,
            sort: 'recommendation' as CompanySort,
            favoriteOnly: fromUrl.favoriteOnly,
        },
        'company-filter',
    ) as Ref<{ query: string; status: string | null; sort: CompanySort; favoriteOnly: boolean }>;

    // 呼び出し側（v-model）は今まで通り項目ごとの ref で扱う。実体は remember された1つの状態
    const field = <K extends keyof typeof state.value>(key: K): WritableComputedRef<(typeof state.value)[K]> =>
        computed({
            get: () => state.value[key],
            set: (v) => (state.value[key] = v),
        });

    const query = field('query');
    const status = field('status');
    const sort = field('sort');
    const favoriteOnly = field('favoriteOnly');

    // 見えている状態を URL に映す。Inertia が覚えている URL ごと差し替える（サーバーには問い合わせない）。
    // history.replaceState を直に呼ぶと、絞り込み状態の保存（useRemember）が Inertia の古い URL で書き戻してしまう
    watch([status, favoriteOnly], ([s, on]) => {
        const url = new URL(window.location.href);
        if (s === null) {
            url.searchParams.delete('status');
        } else {
            url.searchParams.set('status', s);
        }
        if (on) {
            url.searchParams.set('favorite', '1');
        } else {
            url.searchParams.delete('favorite');
        }
        router.replace({ url: `${url.pathname}${url.search}${url.hash}`, preserveState: true, preserveScroll: true });
    });

    const statusOptions = computed<StatusOption[]>(() => {
        const seen = new Map<string, StatusOption>();
        for (const c of toValue(companies)) {
            const hit = seen.get(c.status);
            if (hit) hit.count++;
            else seen.set(c.status, { value: c.status, label: c.statusLabel, count: 1 });
        }

        return [...seen.values()].sort((a, b) => STATUS_ORDER.indexOf(a.value) - STATUS_ORDER.indexOf(b.value));
    });

    /** ★の総数（★だけトグルの件数表示に使う。絞り込み前の全社が母数） */
    const favoriteCount = computed(() => toValue(companies).filter((c) => c.favorite).length);

    const filtered = computed<CompanyRow[]>(() => {
        const q = query.value.trim().toLowerCase();

        return toValue(companies).filter((c) => {
            if (favoriteOnly.value && !c.favorite) return false;
            if (status.value !== null && c.status !== status.value) return false;
            if (q === '') return true;

            return c.name.toLowerCase().includes(q) || (c.position ?? '').toLowerCase().includes(q);
        });
    });

    const rows = computed<CompanyRow[]>(() =>
        [...filtered.value].sort((a, b) => {
            if (a.favorite !== b.favorite) return a.favorite ? -1 : 1;
            if (sort.value === 'recommendation') {
                const d = recommendationRank(a.recommendation) - recommendationRank(b.recommendation);
                if (d !== 0) return d;
            }

            return (b.updatedAt ?? '').localeCompare(a.updatedAt ?? '');
        }),
    );

    return { query, status, sort, favoriteOnly, statusOptions, favoriteCount, rows };
}
