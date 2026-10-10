<script setup lang="ts">
import MetricAddForm from '@/components/company/MetricAddForm.vue';
import MetricBenchmarkNote from '@/components/company/MetricBenchmarkNote.vue';
import ShokubaManualGuide from '@/components/company/ShokubaManualGuide.vue';
import EmptyNote from '@/components/shell/EmptyNote.vue';
import type { EdinetStatus, Metric, Option } from '@/types/company';
import type { Benchmark } from '@/types/issue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    companyId: number;
    officialMetrics: Metric[];
    metricSourceOptions: Option[];
    edinet: EdinetStatus;
    /** 登録済み他社の中での位置。数値として読めない項目には行が無い */
    benchmark: Benchmark[];
}>();

// 順位・中央値との差は PHP が確定済み。ここでは対応する行を引くだけ
const benchmarkFor = computed(() => new Map(props.benchmark.map((b) => [b.metricId, b])));

// 削除は2段階（1クリック即実行にしない）
const confirmingId = ref<number | null>(null);
const remove = (id: number) => {
    router.delete(`/metrics/${id}`, { preserveScroll: true });
    confirmingId.value = null;
};

// gBizINFO（認定・補助金・調達）を法人番号で自動取得
const gbizForm = useForm({});
const fetchGbiz = () => gbizForm.post(`/companies/${props.companyId}/gbizinfo`, { preserveScroll: true });

// EDINET（有報の平均年収等）を非同期取得（初回は書類一覧の走査で数分かかる）
const edinetForm = useForm({});
const fetchEdinet = () => edinetForm.post(`/companies/${props.companyId}/edinet`, { preserveScroll: true });
</script>

<template>
    <section>
        <div class="flex flex-wrap items-baseline justify-between gap-3 border-b-2 border-ink pb-[5px]">
            <!-- 見出しは実態に合わせる: 自動取得は EDINET / gBizINFO のみ、しょくばらぼ等は手入力 -->
            <h3 class="font-serif text-[19px] font-semibold text-ink">
                公式数値 (EDINET / gBizINFO / 手入力)
                <span v-if="edinet.fetchedAt" class="ml-1 font-sans text-[11px] font-normal text-ink-3">
                    EDINET最終取得: <span class="font-mono tabular-nums">{{ edinet.fetchedAt }}</span>
                </span>
            </h3>
            <div class="flex flex-wrap gap-2">
                <button @click="fetchEdinet"
                    :disabled="edinetForm.processing || edinet.isFetching || edinet.blockedReason !== null"
                    class="rounded-[2px] border border-rule-input bg-surface btn-sm text-ink hover:bg-bench disabled:opacity-50"
                    title="有報の平均年間給与・平均年齢・平均勤続年数・従業員数（初回は数分かかります）">
                    {{ edinet.isFetching ? 'EDINET取得中…' : 'EDINETから取得' }}
                </button>
                <button @click="fetchGbiz" :disabled="gbizForm.processing || edinet.gbizBlockedReason !== null"
                    class="rounded-[2px] border border-rule-input bg-surface btn-sm text-ink hover:bg-bench disabled:opacity-50">
                    {{ gbizForm.processing ? '取得中…' : 'gBizINFOから取得' }}
                </button>
            </div>
        </div>
        <!-- 押す前から「なぜ取得できないか」を出す（判定は PHP） -->
        <p v-if="edinet.blockedReason"
            class="mt-[10px] border-l-[3px] border-check bg-check-bg px-[11px] py-[7px] text-[12px] leading-[19px] text-check-fg">
            EDINET: {{ edinet.blockedReason }}
        </p>
        <p v-if="edinet.gbizBlockedReason && edinet.gbizBlockedReason !== edinet.blockedReason"
            class="mt-[10px] border-l-[3px] border-check bg-check-bg px-[11px] py-[7px] text-[12px] leading-[19px] text-check-fg">
            gBizINFO: {{ edinet.gbizBlockedReason }}
        </p>
        <p v-if="gbizForm.errors.gbizinfo"
            class="mt-[10px] border-l-[3px] border-conflict bg-conflict-bg px-[11px] py-[7px] text-[12px] leading-[19px] text-conflict-fg">
            {{ gbizForm.errors.gbizinfo }}
        </p>
        <p v-if="edinetForm.errors.edinet"
            class="mt-[10px] border-l-[3px] border-conflict bg-conflict-bg px-[11px] py-[7px] text-[12px] leading-[19px] text-conflict-fg">
            {{ edinetForm.errors.edinet }}
        </p>
        <p v-if="edinet.error"
            class="mt-[10px] border-l-[3px] border-conflict bg-conflict-bg px-[11px] py-[7px] text-[12px] leading-[19px] text-conflict-fg">
            EDINET取得に失敗しました: {{ edinet.error }}
        </p>
        <p v-if="edinet.isFetching"
            class="mt-[10px] border-l-[3px] border-ink bg-surface px-[11px] py-[7px] text-[12px] leading-[19px] text-ink">
            EDINETから取得中です（初回は書類一覧の走査で数分かかります。完了すると自動で表示されます）
        </p>
        <ul class="mt-[14px] mb-6 list-none border-t border-rule p-0">
            <li v-for="m in officialMetrics" :key="m.id"
                class="flex items-start justify-between gap-3 border-b border-rule-thin py-[9px] text-[13px] leading-[21px] text-ink">
                <span>
                    <!-- 出典（EDINET / gBizINFO / 手入力元のページ）。どこから来た数値かを必ず残す -->
                    <a v-if="m.sourceUrl" :href="m.sourceUrl" target="_blank" rel="noopener noreferrer"
                        class="text-ink underline underline-offset-[3px] hover:text-conflict" title="出典を開く">[{{ m.sourceLabel }}]</a>
                    <span v-else class="text-ink-3">[{{ m.sourceLabel }}]</span>
                    {{ m.key }}: <span class="font-mono font-medium tabular-nums">{{ m.value }}</span><span class="font-medium">{{ m.unit }}</span>
                    <span v-if="m.fiscalYear" class="font-mono text-[11px] tabular-nums text-ink-3">({{ m.fiscalYear }})</span>
                    <MetricBenchmarkNote v-if="benchmarkFor.get(m.id)" :benchmark="benchmarkFor.get(m.id)!" />
                </span>
                <span class="flex shrink-0 gap-2">
                    <template v-if="confirmingId === m.id">
                        <button @click="remove(m.id)"
                            class="rounded-[2px] bg-conflict btn-sm font-medium text-white hover:bg-conflict-fg">削除する</button>
                        <button @click="confirmingId = null"
                            class="rounded-[2px] border border-rule-input bg-white btn-sm text-ink hover:bg-bench">やめる</button>
                    </template>
                    <button v-else @click="confirmingId = m.id"
                        class="rounded-[2px] border border-conflict-border bg-white btn-sm text-conflict hover:bg-conflict-bg">削除</button>
                </span>
            </li>
            <EmptyNote v-if="officialMetrics.length === 0" as="li" class="border-b border-rule-thin">
                公式数値はまだありません。上の「EDINETから取得」「gBizINFOから取得」か、下の入力欄から手で登録してください。
            </EmptyNote>
        </ul>

        <ShokubaManualGuide />
        <MetricAddForm :company-id="companyId" :metric-source-options="metricSourceOptions" />
    </section>
</template>
