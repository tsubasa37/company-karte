<script setup lang="ts">
import type { CodexRateLimit } from '@/types/ai-usage';
import { computed } from 'vue';

const props = defineProps<{ rateLimit: CodexRateLimit }>();

// 残りが少ないほど強い色にする。閾値はコード側で固定し、画面での判断を挟まない
// （信号の3色はやめ、墨→要確認→矛盾の3段にする。数値は隣に出したままにする）
const barCls = computed(() => {
    const p = props.rateLimit.usedPercent ?? 0;
    if (p >= 90) return 'bg-conflict';
    if (p >= 70) return 'bg-check';

    return 'bg-ink';
});
</script>

<template>
    <div class="border-l-[3px] border-ink pl-3">
        <template v-if="rateLimit.available && rateLimit.usedPercent !== null">
            <div class="mb-1 flex flex-wrap items-baseline justify-between gap-2.5">
                <span class="text-[12px] font-medium text-ink">
                    利用枠の消費 <span class="font-mono tabular-nums">{{ rateLimit.usedPercent }}</span>%
                    <span v-if="rateLimit.windowLabel" class="font-normal text-ink-3">
                        （{{ rateLimit.windowLabel }}ごとの枠）
                    </span>
                </span>
                <span v-if="rateLimit.planType" class="text-[11px] text-ink-3">plan: {{ rateLimit.planType }}</span>
            </div>
            <div class="h-1.5 w-full bg-rule">
                <div class="h-1.5 transition-all" :class="barCls" :style="{ width: `${rateLimit.usedPercent}%` }" />
            </div>
            <p class="mt-[5px] text-[11px] text-ink-3">
                <span v-if="rateLimit.resetsAt">リセット: <span class="font-mono tabular-nums">{{ rateLimit.resetsAt }}</span></span>
                <span v-if="rateLimit.observedAt" class="ml-2">計測時点: <span class="font-mono tabular-nums">{{
                    rateLimit.observedAt
                }}</span></span>
            </p>
            <p class="mt-[5px] text-[11px] text-ink-3">
                この数値は codex を使う全ツールの合計です（このアプリ以外の利用も含みます）。
            </p>
        </template>
        <p v-else class="text-[11px] leading-[18px] text-ink-2">
            利用枠を取得できませんでした: {{ rateLimit.unavailableReason }}
        </p>
    </div>
</template>
