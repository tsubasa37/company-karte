<script setup lang="ts">
import type { ScheduleEvent } from '@/types/schedule';
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

// 日付ラベル・場所をリンクにできるかの判定は PHP(ScheduleEventPresenter) で確定済み
const props = defineProps<{
    event: ScheduleEvent;
    /** 企業ページでは企業名が自明なので出さない */
    hideCompany?: boolean;
    /**
     * 枠で囲む形にする。メール履歴（/mails）で取り込み行の中に入れ子で置くときだけ true。
     * 既定（false）は予定表（/schedule）の一覧行 — 左に墨罫、下に細罫、枠なし。
     */
    boxed?: boolean;
}>();

const emit = defineEmits<{ edit: [ScheduleEvent] }>();

// 枠と余白だけが2画面で違う。中身（日時・種別・由来・企業・所要・操作・場所・メモ）は共通
const rootClass = computed(() =>
    props.boxed
        ? ['border border-rule-input bg-surface px-3 py-[10px]', props.event.past ? 'opacity-60' : '']
        : [
              'border-b border-b-rule-thin border-l-[3px] py-3 pl-[14px]',
              // 過去は左罫を落として薄墨に沈める（操作ボタンも一緒に沈む）
              props.event.past ? 'border-l-transparent text-ink-3 opacity-60' : 'border-l-ink',
          ],
);

const remove = (event: ScheduleEvent) => {
    if (!confirm(`${event.dateLabel} の「${event.displayTitle}」を削除します。よろしいですか？`)) return;
    router.delete(`/schedule/${event.id}`, { preserveScroll: true });
};
</script>

<template>
    <div :class="rootClass">
        <div class="flex flex-wrap items-baseline justify-between gap-[10px]">
            <div class="flex flex-wrap items-baseline gap-[10px]">
                <span class="font-serif font-semibold" :class="boxed ? 'text-[14px]' : 'text-[15px]'">
                    {{ event.dateLabel }}
                </span>
                <span class="border-l-2 border-ink pl-[6px] text-[12px]">{{ event.displayTitle }}</span>
                <!-- 由来バッジ（メール由来のときだけ presenter が「📧 メール」を入れてくる） -->
                <span v-if="event.sourceBadge" :title="event.sourceLabel"
                    class="rounded-[2px] bg-match-bg px-[6px] py-px text-[11px] whitespace-nowrap text-match-fg">
                    {{ event.sourceBadge }}
                </span>
                <Link v-if="!hideCompany" :href="`/companies/${event.companyId}`"
                    class="text-[12px] text-ink-2 hover:text-conflict hover:underline hover:underline-offset-[3px]">
                    {{ event.companyName }}
                </Link>
                <span v-if="event.durationMinutes" class="text-[11px] tabular-nums text-ink-3">
                    {{ event.durationMinutes }}分
                </span>
            </div>
            <div class="flex shrink-0 gap-[6px]">
                <a :href="event.icsUrl"
                    class="rounded-[2px] border border-rule-input bg-white btn-sm whitespace-nowrap text-ink hover:bg-bench"
                    title="カレンダーに取り込む .ics をダウンロードします（押したときだけ反映されます）">📅 カレンダーに追加</a>
                <button type="button"
                    class="rounded-[2px] border border-rule-input bg-white btn-sm text-ink hover:bg-bench"
                    @click="emit('edit', event)">編集</button>
                <button type="button"
                    class="rounded-[2px] border border-conflict-border bg-white btn-sm text-conflict hover:bg-conflict-bg"
                    @click="remove(event)">削除</button>
            </div>
        </div>
        <p v-if="event.place" class="mt-[7px] text-[11px]">
            <span class="text-ink-3">場所: </span>
            <a v-if="event.placeUrl" :href="event.placeUrl" target="_blank" rel="noopener noreferrer"
                class="text-ink underline underline-offset-[3px] hover:text-conflict">{{ event.place }}</a>
            <span v-else class="text-ink-2">{{ event.place }}</span>
        </p>
        <p v-if="event.note" class="mt-1 text-[11px] leading-[18px] whitespace-pre-wrap text-ink-2">{{ event.note }}</p>
    </div>
</template>
