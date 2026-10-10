<script setup lang="ts">
import type { AlertBlock } from '@/types/company';
import type { DeadlineAlert } from '@/types/dashboardAlerts';
import { Link } from '@inertiajs/vue3';

// 「あと何日 / 今日 / 過ぎている」の文言・並び順は PHP（BuildDashboardAction）で確定済み
defineProps<{
    deadlines: AlertBlock<DeadlineAlert>;
    /** 台帳の通し番号（確定は Dashboard.vue） */
    no: number;
}>();
</script>

<template>
    <section v-if="deadlines.total"
        class="border-b border-b-rule-thin border-l-[3px] border-l-check bg-surface py-[13px] pr-5 pl-[17px]">
        <div class="grid grid-cols-[2.4rem_minmax(0,1fr)] gap-3">
            <div class="pt-px text-right">
                <div class="font-mono text-[11px] tabular-nums text-ink-4">{{ no }}</div>
                <div class="font-serif text-[15px] leading-[1.2] text-check" aria-hidden="true">○</div>
            </div>
            <div class="min-w-0">
                <h2 class="font-serif text-[15px] font-semibold text-check">選考の期限（{{ deadlines.total }}件）</h2>
                <ul class="mt-2 flex flex-col gap-1.5">
                    <li v-for="d in deadlines.items" :key="`${d.id}-${d.kind}`"
                        class="flex flex-wrap items-baseline gap-2">
                        <Link :href="`/companies/${d.id}?tab=info`"
                            class="text-[13px] font-medium text-ink hover:text-conflict hover:underline">
                            {{ d.name }}
                        </Link>
                        <span class="text-[12px] leading-[19px]"
                            :class="d.overdue ? 'font-semibold text-conflict' : 'text-check'">
                            {{ d.label }}
                        </span>
                    </li>
                    <li v-if="deadlines.more" class="text-[12px] text-ink-3">他{{ deadlines.more }}件</li>
                </ul>
            </div>
        </div>
    </section>
</template>
