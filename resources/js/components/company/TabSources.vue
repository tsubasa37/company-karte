<script setup lang="ts">
import InterviewRecordForm from '@/components/company/InterviewRecordForm.vue';
import OfficialMetrics from '@/components/company/OfficialMetrics.vue';
import SourceAddForm from '@/components/company/SourceAddForm.vue';
import SourceListItem from '@/components/company/SourceListItem.vue';
import EmptyNote from '@/components/shell/EmptyNote.vue';
import type { EdinetStatus, Metric, Option, Source } from '@/types/company';
import type { Benchmark } from '@/types/issue';
import { computed, ref } from 'vue';

const props = defineProps<{
    companyId: number;
    sources: Source[];
    officialMetrics: Metric[];
    sourceTypeOptions: Option[];
    metricSourceOptions: Option[];
    edinet: EdinetStatus;
    /** 公式数値の、登録済み他社の中での位置 */
    benchmark: Benchmark[];
}>();

// 種別フィルタ（面接メモ・ニュースが増えると単一リストでは追えないため）
const typeFilter = ref<string | null>(null);
const typeChips = computed(() => {
    const m = new Map<string, { label: string; count: number }>();
    for (const s of props.sources) {
        const e = m.get(s.type);
        if (e) e.count++;
        else m.set(s.type, { label: s.typeLabel, count: 1 });
    }
    return [...m.entries()].map(([type, v]) => ({ type, ...v }));
});
const visibleSources = computed(() =>
    typeFilter.value ? props.sources.filter((s) => s.type === typeFilter.value) : props.sources,
);

// 絞り込みの見た目だけを決める（選択中は墨ベタ・白抜き。企業一覧の絞り込みと同じ規則）
const segCls = (active: boolean) =>
    active ? 'border-ink bg-ink text-paper' : 'border-rule text-ink-2 hover:bg-bench';
</script>

<template>
    <div class="space-y-10">
        <!-- 行全体をクリックすると全文ページへ別タブで開く（削除は詳細ページに集約） -->
        <section>
            <div class="flex flex-wrap items-baseline justify-between gap-3 border-b-2 border-ink pb-[5px]">
                <h3 class="font-serif text-[19px] font-semibold text-ink">
                    投入したソース (<span class="font-mono tabular-nums">{{ sources.length }}</span>)
                </h3>
                <!-- 下の入力欄（面接記録・貼り付け）を書きかけのまま失わないよう別タブで開く -->
                <a :href="`/companies/${companyId}/news`" target="_blank" rel="noopener noreferrer"
                    class="rounded-[2px] border border-rule-input bg-white btn-sm text-ink hover:bg-bench">
                    ニュースを取得
                </a>
            </div>
            <!-- 種別の絞り込み。丸ピルをやめ1本の枠に収め、選択中だけ墨ベタ（白抜き）にする -->
            <div v-if="typeChips.length > 1"
                class="mt-[14px] flex w-fit max-w-full flex-wrap items-stretch overflow-hidden rounded-[2px] border border-rule-input bg-white">
                <button @click="typeFilter = null" class="border-r px-[10px] py-1 text-[12px]"
                    :class="segCls(typeFilter === null)" :aria-pressed="typeFilter === null">
                    すべて <span class="font-mono tabular-nums">{{ sources.length }}</span>
                </button>
                <button v-for="c in typeChips" :key="c.type" @click="typeFilter = typeFilter === c.type ? null : c.type"
                    class="border-r px-[10px] py-1 text-[12px] last:border-r-0" :class="segCls(typeFilter === c.type)"
                    :aria-pressed="typeFilter === c.type">
                    {{ c.label }} <span class="font-mono tabular-nums">{{ c.count }}</span>
                </button>
            </div>
            <ul class="mt-[14px] mb-6 border border-rule-input bg-surface">
                <SourceListItem v-for="s in visibleSources" :key="s.id" :s="s" />
                <EmptyNote v-if="sources.length === 0" as="li">
                    未投入。下のフォームから求人票・口コミを追加してください。
                </EmptyNote>
            </ul>

            <SourceAddForm :company-id="companyId" :source-type-options="sourceTypeOptions" />
        </section>

        <InterviewRecordForm :company-id="companyId" />

        <OfficialMetrics :company-id="companyId" :official-metrics="officialMetrics"
            :metric-source-options="metricSourceOptions" :edinet="edinet" :benchmark="benchmark" />
    </div>
</template>
