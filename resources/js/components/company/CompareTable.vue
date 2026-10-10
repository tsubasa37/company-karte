<script setup lang="ts">
import CompareTableRow from '@/components/company/CompareTableRow.vue';
import type { CompareRow } from '@/types/company';
import { computed } from 'vue';

const props = defineProps<{ rows: CompareRow[]; currentSalary: number | null }>();

/**
 * 上段のグループ見出し。12列は1列も減らさない代わりに、横スクロール中でも
 * 「いまどの群を見ているか」が分かるようにする。span の合計は固定列を除く11列。
 */
const GROUPS = [
    { label: '判定（分析済みの企業のみ）', span: 5 },
    { label: '求人票の条件（AI抽出・記載の抜き出しのみ）', span: 4 },
    { label: '選考', span: 2 },
] as const;

/**
 * 企業名（固定列）を除く11列。
 * width は列の最低幅、edge は群の境界（右に細罫を引く列）。
 */
const cols = computed(() => [
    { label: 'おすすめ度・理由', width: 'min-w-[16rem]', edge: false },
    { label: '必須充足', width: '', edge: false },
    { label: 'NG', width: '', edge: false },
    { label: '歓迎充足', width: '', edge: false },
    { label: '矛盾/検証不能', width: '', edge: true },
    { label: '年収', width: 'min-w-[13rem]', edge: false },
    { label: '勤務地', width: 'min-w-[8rem]', edge: false },
    { label: 'リモート', width: 'min-w-[10rem]', edge: false },
    { label: '休日', width: 'min-w-[11rem]', edge: true },
    { label: props.currentSalary !== null ? 'オファー（現年収比）' : 'オファー', width: 'min-w-[11rem]', edge: false },
    { label: '状態', width: 'min-w-[7rem]', edge: false },
]);
</script>

<template>
    <div class="overflow-x-auto">
        <!-- 列を画面幅に押し込まない。列に最低幅を与え、あふれたら横スクロール。
             行内に補助行（判定理由・レンジ幅注記など）が入るためセルは上揃えにする。
             border-collapse を separate にしているのは、sticky な列でも罫線が消えないようにするため
             （collapse だと罫線が表側に描かれ、スクロールした列の下に潜って途切れる） -->
        <table class="w-full min-w-[86rem] border-separate border-spacing-0 text-[13px] [&_td]:align-top">
            <thead>
                <!-- 群の見出し。判定5列 / 求人票の条件4列 / 選考2列 -->
                <tr>
                    <th scope="col"
                        class="sticky left-0 z-20 min-w-[11rem] border-r border-b border-r-ink border-b-rule-input bg-group-head px-4 py-[5px] text-left text-[10px] font-medium tracking-[.1em] text-ink-3">
                        固定
                    </th>
                    <th v-for="(g, i) in GROUPS" :key="g.label" scope="colgroup" :colspan="g.span"
                        class="border-b border-b-rule-input bg-group-head px-4 py-[5px] text-left text-[10px] font-medium tracking-[.1em] text-ink-2"
                        :class="i < GROUPS.length - 1 ? 'border-r border-r-rule-input' : ''">
                        {{ g.label }}
                    </th>
                </tr>
                <tr>
                    <!-- 必ず横スクロールが起きる幅のため、企業名は固定して見失わないようにする。
                         スクロールで下の列が透けないよう不透明な背景を持たせる -->
                    <th scope="col"
                        class="sticky left-0 z-20 min-w-[11rem] border-r border-b border-r-ink border-b-ink bg-bench px-4 py-[7px] text-left text-[11px] font-semibold text-ink">
                        企業
                    </th>
                    <th v-for="c in cols" :key="c.label" scope="col"
                        class="border-b border-b-ink bg-bench px-4 py-[7px] text-left text-[11px] font-medium whitespace-nowrap text-ink-2"
                        :class="[c.width, c.edge ? 'border-r border-r-rule-input' : '']">
                        {{ c.label }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <CompareTableRow v-for="(r, i) in rows" :key="r.id" :r="r" :index="i" />
            </tbody>
        </table>
    </div>
</template>
