<script setup lang="ts">
import ScheduleItem from '@/components/schedule/ScheduleItem.vue';
import type { MailIngestRow } from '@/types/mail';
import { Link, router } from '@inertiajs/vue3';

defineProps<{ ingest: MailIngestRow }>();

const toggleReviewed = (ingest: MailIngestRow) => {
    router.patch(`/mails/${ingest.id}/reviewed`, { reviewed: !ingest.reviewed }, { preserveScroll: true });
};

const revert = (ingest: MailIngestRow) => {
    const message = `「${ingest.subject}」の取り込みを取り消します。\n`
        + 'このメールから作られた企業・ソース・予定を削除します。\n'
        + '取り込みのあとに書いた選考メモ・オファー・面接記録がある企業は削除せずに残します。\n'
        + 'よろしいですか？';
    if (!confirm(message)) return;
    router.delete(`/mails/${ingest.id}`, { preserveScroll: true });
};

// やり直しは AI を1回使うので、押す前にそのことを出す
const retry = (ingest: MailIngestRow) => {
    if (!confirm(`「${ingest.subject}」をもう一度取り込みます（AIを1回使います）。よろしいですか？`)) return;
    router.post(`/mails/${ingest.id}/retry`, {}, { preserveScroll: true });
};

// 失敗は矛盾色の左罫で目立たせる。未確認は墨罫＋白地、確認済みは罫を落として薄墨に沈める
const rowCls = (ingest: MailIngestRow) => {
    if (ingest.failed) return 'border-l-conflict bg-surface text-ink';

    return ingest.reviewed ? 'border-l-transparent bg-surface-alt text-ink-2' : 'border-l-ink bg-surface text-ink';
};
</script>

<template>
    <li class="border-b border-b-rule-thin border-l-[3px] py-[14px] pr-5 pl-[17px]" :class="rowCls(ingest)">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <div class="flex flex-wrap items-baseline gap-[10px]">
                <span class="font-mono text-[11px] tabular-nums text-ink-3">{{ ingest.receivedAt }}</span>
                <span class="border-l-2 border-ink pl-[6px] text-[11px] text-ink-2">{{ ingest.mailKindLabel }}</span>
                <span v-if="ingest.failed"
                    class="rounded-[2px] bg-conflict-bg px-[6px] py-px text-[11px] whitespace-nowrap text-conflict-fg">取り込み失敗</span>
                <span v-else-if="!ingest.reviewed"
                    class="rounded-[2px] bg-check-bg px-[6px] py-px text-[11px] whitespace-nowrap text-check-fg">未確認</span>
                <span class="font-serif text-[15px] font-semibold">{{ ingest.subject }}</span>
            </div>
            <div class="flex shrink-0 gap-2">
                <button v-if="ingest.failed" type="button"
                    class="rounded-[2px] bg-ink btn-sm whitespace-nowrap font-medium text-paper hover:bg-ink-2"
                    @click="retry(ingest)">もう一度取り込む</button>
                <button v-else type="button"
                    class="rounded-[2px] border border-rule-input bg-white btn-sm whitespace-nowrap text-ink hover:bg-bench"
                    @click="toggleReviewed(ingest)">{{ ingest.reviewed ? '未確認に戻す' : '確認した' }}</button>
                <button v-if="ingest.revertible" type="button"
                    class="rounded-[2px] border border-conflict-border bg-white btn-sm whitespace-nowrap text-conflict hover:bg-conflict-bg"
                    @click="revert(ingest)">取り込みを取り消す</button>
            </div>
        </div>

        <!-- 失敗の理由と、次回の自動取得では再処理しないこと（AI を使い直さないため）を明示する -->
        <p v-if="ingest.failed" class="mt-[7px] text-[12px] leading-5 text-conflict-fg">
            失敗の理由: {{ ingest.error }}<br />
            次回の自動取得ではこのメールを再処理しません。原因を直してから「もう一度取り込む」を押してください。
        </p>

        <p class="mt-[7px] text-[11px] text-ink-3">
            <span class="font-mono">{{ ingest.fromDomain }}</span>
            <template v-if="ingest.companyName">
                <span> → </span>
                <Link :href="`/companies/${ingest.companyId}`"
                    class="text-ink underline underline-offset-[3px] hover:text-conflict">{{ ingest.companyName }}</Link>
            </template>
            <span v-else-if="!ingest.failed" class="text-ink-4"> → 企業は登録されていません（転職関連と判定されませんでした）</span>
        </p>
        <p v-if="ingest.summary" class="mt-[6px] text-[12px] leading-5 text-ink-2">{{ ingest.summary }}</p>

        <div v-if="ingest.scheduleEvent" class="mt-[10px]">
            <ScheduleItem :event="ingest.scheduleEvent" boxed />
        </div>

        <!-- 日時候補が複数あるときはアプリが勝手に1つ選ばない。本人が返信して決めてから登録する -->
        <div v-else-if="ingest.eventCandidates.length" class="mt-[10px] border-l-[3px] border-ink-5 bg-bench px-3 py-[9px]">
            <p class="mb-[5px] text-[11px] text-ink-2">
                日時候補が{{ ingest.eventCandidates.length }}件提示されています（どれになるかは返信後に決まるため、予定は作っていません）
            </p>
            <ul class="list-disc pl-[18px] text-[12px] leading-[21px] text-ink">
                <li v-for="(c, i) in ingest.eventCandidates" :key="i">
                    <span class="font-mono tabular-nums">{{ c.starts_at }}</span><span v-if="c.title"> — {{ c.title }}</span><span
                        v-if="c.place"> / {{ c.place }}</span>
                </li>
            </ul>
        </div>
    </li>
</template>
