<script setup lang="ts">
import type { AlertBlock } from '@/types/company';
import type { MailUnreviewedItem } from '@/types/dashboardAlerts';
import { Link } from '@inertiajs/vue3';

// メール取り込みは承認ゲートを置かず自動登録するため、未確認をここに必ず出す
defineProps<{
    mailUnreviewed: AlertBlock<MailUnreviewedItem>;
    /** 台帳の通し番号（確定は Dashboard.vue） */
    no: number;
}>();
</script>

<template>
    <section v-if="mailUnreviewed.total"
        class="border-b border-b-rule-thin border-l-[3px] border-l-check bg-surface py-[13px] pr-5 pl-[17px]">
        <div class="grid grid-cols-[2.4rem_minmax(0,1fr)] gap-3">
            <div class="pt-px text-right">
                <div class="font-mono text-[11px] tabular-nums text-ink-4">{{ no }}</div>
                <div class="font-serif text-[15px] leading-[1.2] text-check" aria-hidden="true">○</div>
            </div>
            <div class="min-w-0">
                <h2 class="font-serif text-[15px] font-semibold text-check">
                    メールから自動登録（未確認{{ mailUnreviewed.total }}件）
                </h2>
                <ul class="mt-2 flex flex-col gap-1.5">
                    <li v-for="m in mailUnreviewed.items" :key="m.id" class="flex flex-wrap items-baseline gap-2">
                        <span class="font-mono text-[11px] tabular-nums text-ink-3">{{ m.receivedAt }}</span>
                        <span class="text-[11px] text-ink-3">{{ m.kindLabel }}</span>
                        <Link v-if="m.companyId" :href="`/companies/${m.companyId}`"
                            class="text-[13px] font-medium text-ink hover:text-conflict hover:underline">{{ m.companyName }}</Link>
                        <span class="text-[12px] leading-[19px] text-ink-2">{{ m.subject }}</span>
                    </li>
                    <li v-if="mailUnreviewed.more" class="text-[12px] text-ink-3">他{{ mailUnreviewed.more }}件</li>
                </ul>
                <Link href="/mails"
                    class="mt-2 inline-block text-[12px] text-ink-2 underline underline-offset-[3px] hover:text-conflict">
                    取り込み履歴で確認する
                </Link>
            </div>
        </div>
    </section>
</template>
