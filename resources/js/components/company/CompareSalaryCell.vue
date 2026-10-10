<script setup lang="ts">
import type { CompareRow } from '@/types/company';

const props = defineProps<{
    r: CompareRow;
    /** 記載が無い場合に「なぜ空なのか」を示す文言（親が判定済み） */
    missing: string;
}>();

/** 年収レンジの表記。数値があればレンジ、無ければ求人票の記載文をそのまま出す */
const range = (): string => {
    const c = props.r.conditions;
    if (!c) return '';
    if (c.salary_min !== null && c.salary_max !== null) return `${c.salary_min}〜${c.salary_max}万`;
    if (c.salary_min !== null) return `${c.salary_min}万〜`;
    if (c.salary_max !== null) return `〜${c.salary_max}万`;
    return c.salary_text;
};

// フレックス・残業代・補足は年収の直下に常時表示（ホバー限定にしない）
const details = (): string => {
    const c = props.r.conditions;
    return c ? [c.flex, c.overtime_pay, c.notes].filter(Boolean).join(' / ') : '';
};
</script>

<template>
    <td class="border-b border-rule-thin px-4 py-[10px]">
        <div v-if="range()" class="font-mono text-[13px] whitespace-nowrap text-ink tabular-nums">{{ range() }}</div>
        <div v-else class="text-[12px] text-ink-4">{{ missing }}</div>

        <!-- レンジ幅の倍率・差額は PHP の SalaryRangeSignal が確定計算済み。
             断定はせず、下限採用の可能性を面接で確認する方向に促す（注意は絵文字でなく色で示す） -->
        <p v-if="r.salaryRange?.isWide" class="mt-[5px] max-w-[13rem] text-[11px] leading-[17px] font-medium text-check">
            {{ r.salaryRange.note }}
        </p>
        <p
            v-else-if="r.salaryRange?.hasRange && r.salaryRange.unavailableReason"
            class="mt-[5px] max-w-[13rem] text-[11px] leading-[17px] text-ink-4"
        >
            {{ r.salaryRange.unavailableReason }}
        </p>

        <p v-if="details()" class="mt-[5px] max-w-[13rem] text-[11px] leading-[17px] text-ink-3">{{ details() }}</p>

        <p v-if="r.avgSalary" class="mt-[5px] text-[11px] leading-[17px] whitespace-nowrap text-ink-2">
            公式平均 <span class="font-mono tabular-nums">{{ r.avgSalary.value }}</span>{{ r.avgSalary.unit }}
            <span v-if="r.avgSalary.fiscalYear">（{{ r.avgSalary.fiscalYear }}）</span>
        </p>
    </td>
</template>
