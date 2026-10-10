<script setup lang="ts">
import VerdictTable from '@/components/karte/VerdictTable.vue';
import type { P2Verdicts } from '@/types/karte';

/**
 * 全件が「検証不能」だったときの専用表示。
 *
 * 通常の並び（判定チップ → 否定文 → 折りたたみ）は、矛盾も整合も 0 件のときに
 * 「0 が2つ並ぶチップ」「まだありません という否定文」「閉じた折りたたみ2本」だけを残し、
 * 表が1行も出ない状態になる。ここでは見出し1行と表に置き換えて、要素を減らす。
 */
const props = defineProps<{
    /** 通常重要度の検証不能（表に出す） */
    verdicts: P2Verdicts['verdicts'];
    /** 重要度・低（この表示に入る時点で、すべて検証不能） */
    low: P2Verdicts['verdicts'];
    /** 重要度・低を含む検証不能の総数（PHP が集計した counts.unverifiable） */
    total: number;
    companyId: number;
    runId: number;
    hasMachine: boolean;
}>();

// 表に出す行が無い（全件が重要度・低）ときは、畳んだままだと中身がまた見えなくなる
const openLow = props.verdicts.length === 0;
</script>

<template>
    <div>
        <p class="mt-3 font-serif text-[15px] font-bold text-ink">検証不能のみ {{ total }}件</p>
        <p class="mt-[3px] text-[12px] leading-[20px] text-ink-2">
            材料を足すと 整合／矛盾 に変わります。
            <template v-if="verdicts.length && low.length">
                内訳は下の{{ verdicts.length }}件と、重要度・低の{{ low.length }}件。
            </template>
            <template v-else-if="!verdicts.length">すべて重要度・低の主張です。</template>
        </p>

        <VerdictTable v-if="verdicts.length" :verdicts="verdicts" :company-id="companyId" :run-id="runId"
            :has-machine="hasMachine" />

        <!-- 重要度・低（設立年・所在地など応募判断に影響しにくい主張）は畳んだまま残す -->
        <details v-if="low.length" class="mt-4 border-t border-rule pt-[10px]" :open="openLow">
            <summary class="cursor-pointer text-[13px] font-medium text-ink-2 hover:text-ink">
                重要度・低 {{ low.length }}件（すべて検証不能。設立年・所在地・法定義務の制度など）
            </summary>
            <VerdictTable :verdicts="low" :company-id="companyId" :run-id="runId" :has-machine="hasMachine" />
        </details>
    </div>
</template>
