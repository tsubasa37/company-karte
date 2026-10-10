<script setup lang="ts">
import type { AlertBlock, DashboardCompany } from '@/types/company';
import { Link } from '@inertiajs/vue3';

// 件数・並び順・上位件数の切り出しは PHP（BuildDashboardAction）で確定済み。ここは描画のみ
defineProps<{
    openIssues: AlertBlock<DashboardCompany & { pendingCount: number }>;
    /** 台帳の通し番号（確定は Dashboard.vue） */
    no: number;
}>();
</script>

<template>
    <section v-if="openIssues.total"
        class="border-b border-b-rule-thin border-l-[3px] border-l-transparent py-[13px] pr-5 pl-[17px]"
        :class="no % 2 === 0 ? 'bg-surface-alt' : 'bg-surface'">
        <div class="grid grid-cols-[2.4rem_minmax(0,1fr)] gap-3">
            <div class="pt-px text-right">
                <div class="font-mono text-[11px] tabular-nums text-ink-4">{{ no }}</div>
                <div class="font-serif text-[15px] leading-[1.2] text-check" aria-hidden="true">?</div>
            </div>
            <div class="min-w-0">
                <h2 class="font-serif text-[15px] font-semibold text-ink">
                    面接で確認する論点が残っている企業（{{ openIssues.total }}件）
                </h2>
                <p class="mt-[5px] text-[12px] leading-5 text-ink-2">
                    面接メモを投入すると、答えが出た論点は自動で消し込まれます。
                </p>
                <ul class="mt-2 flex flex-wrap gap-x-[18px] gap-y-[5px]">
                    <li v-for="c in openIssues.items" :key="c.id" class="flex flex-wrap items-baseline gap-2">
                        <Link :href="`/companies/${c.id}?tab=interview`"
                            class="text-[13px] font-medium text-ink hover:text-conflict hover:underline">
                            {{ c.name }}
                        </Link>
                        <span class="font-mono text-[12px] tabular-nums text-check-fg">
                            未解消 {{ c.pendingCount }}
                        </span>
                    </li>
                    <li v-if="openIssues.more" class="text-[12px] text-ink-3">他{{ openIssues.more }}件</li>
                </ul>
            </div>
        </div>
    </section>
</template>
