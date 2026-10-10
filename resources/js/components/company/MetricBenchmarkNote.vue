<script setup lang="ts">
import HelpTip from '@/components/HelpTip.vue';
import { HELP } from '@/constants/help';
import type { Benchmark } from '@/types/issue';
import { computed } from 'vue';

const props = defineProps<{ benchmark: Benchmark }>();

// 母集団は「自分が登録した企業」であって業界平均ではない。文言でそれを明示する
const label = computed(() => `登録${props.benchmark.population}社中 ${props.benchmark.rank}位`);

// 中央値との差。0 は「差なし」なので上下の色を付けない
const direction = computed(() => {
    const diff = props.benchmark.diff ?? 0;

    return diff > 0 ? 'text-match-fg' : diff < 0 ? 'text-conflict-fg' : 'text-ink-3';
});

const signed = (n: number) => (n > 0 ? `+${n}` : `${n}`);
</script>

<template>
    <span v-if="benchmark.comparable" class="ml-[8px] inline-flex items-baseline gap-[6px] align-baseline">
        <span class="bg-bench px-[6px] font-mono text-[11px] tabular-nums text-ink-2">{{ label }}</span>
        <HelpTip :help="HELP.benchmark" />
        <span class="font-mono text-[11px] tabular-nums" :class="direction">
            中央値 {{ signed(benchmark.diff ?? 0) }}{{ benchmark.unit }}
            <template v-if="benchmark.diffPercent !== null">（{{ signed(benchmark.diffPercent) }}%）</template>
        </span>
    </span>
    <!-- 比較できないことを黙って隠さない（何社あれば出るかは PHP が文言で持つ） -->
    <span v-else class="ml-[8px] text-[11px] text-ink-4">{{ benchmark.reason }}</span>
</template>
