<script setup lang="ts">
import type { RunningRunAlert } from '@/types/dashboard';
import { Link, router } from '@inertiajs/vue3';
import { onUnmounted, watch } from 'vue';

// 進捗（done件数/全件）とラベルはPHP（BuildDashboardAction）で確定済み。ここは描画とポーリングのみ
const props = defineProps<{
    running: RunningRunAlert[];
    /** 台帳の通し番号（確定は Dashboard.vue） */
    no: number;
}>();

// 実行中が1件以上のときだけ running ブロックだけを部分リロードする。
// 全体集計（全社走査・failed_jobs・jobs・バックアップ走査）は only で除外して評価させない
let timer: number | undefined;
const stopPolling = () => {
    if (timer) {
        clearInterval(timer);
        timer = undefined;
    }
};
watch(
    () => props.running.length > 0,
    (active) => {
        if (active && !timer) {
            timer = window.setInterval(() => router.reload({ only: ['running'] }), 2500);
        } else if (!active && timer) {
            // 0件になった瞬間だけ全体を1回取り直す（分析結果を他ブロックにも反映するため）
            stopPolling();
            router.reload();
        }
    },
    { immediate: true },
);
onUnmounted(stopPolling);
</script>

<template>
    <section v-if="running.length"
        class="border-b border-b-rule-thin border-l-[3px] border-l-transparent py-[13px] pr-5 pl-[17px]"
        :class="no % 2 === 0 ? 'bg-surface-alt' : 'bg-surface'">
        <div class="grid grid-cols-[2.4rem_minmax(0,1fr)] gap-3">
            <div class="pt-px text-right">
                <div class="font-mono text-[11px] tabular-nums text-ink-4">{{ no }}</div>
                <div class="font-serif text-[15px] leading-[1.2] text-ink" aria-hidden="true">·</div>
            </div>
            <div class="min-w-0">
                <h2 class="font-serif text-[15px] font-semibold text-ink">分析を実行中（{{ running.length }}件）</h2>
                <ul class="mt-2 flex flex-col gap-1.5">
                    <li v-for="r in running" :key="r.runId" class="flex flex-wrap items-baseline gap-2">
                        <Link :href="`/companies/${r.id}?tab=karte`"
                            class="text-[13px] font-medium text-ink hover:text-conflict hover:underline">
                            {{ r.name }}
                        </Link>
                        <span class="text-[12px] leading-[19px] text-ink-2">
                            {{ r.statusLabel }}<template v-if="r.currentStepLabel">（{{ r.currentStepLabel }}）</template>
                            ・ステップ {{ r.stepsDone }}/{{ r.stepsTotal }}
                            <template v-if="r.startedAt">・開始 {{ r.startedAt }}</template>
                        </span>
                        <!-- 応答なし（判定は PHP）。中断は企業ページの進捗欄から行う -->
                        <span v-if="r.isStuck" class="text-[12px] leading-[19px] text-check-fg">
                            応答なし。企業ページから中断できます
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
