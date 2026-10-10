<script setup lang="ts">
import ScheduleCalendar from '@/components/schedule/ScheduleCalendar.vue';
import ScheduleForm from '@/components/schedule/ScheduleForm.vue';
import ScheduleList from '@/components/schedule/ScheduleList.vue';
import StatusBar from '@/components/shell/StatusBar.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { Option } from '@/types/company';
import type { ScheduleCalendar as Calendar, ScheduleCompanyOption, ScheduleEvent, ScheduleGroup } from '@/types/schedule';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    /** 今週/来週/それ以降/過去。区分と並び順は PHP 側で確定済み */
    groups: ScheduleGroup[];
    calendar: Calendar;
    companies: ScheduleCompanyOption[];
    kindOptions: Option[];
}>();

const view = ref<'list' | 'calendar'>('list');
const showForm = ref(false);
const editing = ref<ScheduleEvent | null>(null);

const startEdit = (event: ScheduleEvent) => {
    editing.value = event;
    showForm.value = true;
};

const closeForm = () => {
    editing.value = null;
    showForm.value = false;
};

// 編集中に押された場合は「新規追加に切り替える」であって、閉じる操作ではない
const startCreate = () => {
    const wasEditing = editing.value !== null;
    editing.value = null;
    showForm.value = wasEditing || !showForm.value;
};

// 上と同じ理由で、「閉じる」になるのは新規追加のフォームが開いているときだけ
const createLabel = computed(() => (showForm.value && editing.value === null ? '閉じる' : '＋ 予定を追加'));

// 状態バー・レールの数字は、いま画面に出ている予定から数えるだけ（props は増やさない）
const events = computed(() => props.groups.flatMap((g) => g.events));
const upcoming = computed(() => events.value.filter((e) => !e.past).length);
const thisWeek = computed(() => props.groups.find((g) => g.key === 'thisWeek')?.events.length ?? 0);
const fromMail = computed(() => events.value.filter((e) => e.sourceBadge !== null).length);

// 「今日」の基準日だけは props に無い（calendar は表示中の月なので、前月へ移ると今日が消える）。
// ローカル専用アプリなのでこの端末の日付で数える
const pad = (n: number) => String(n).padStart(2, '0');
const now = new Date();
const todayKey = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
const todayCount = computed(() => events.value.filter((e) => e.date === todayKey).length);

const status = computed(() => [
    { label: '登録済みの予定', value: events.value.length, note: '件' },
    { label: 'これから', value: upcoming.value, note: `件（今週${thisWeek.value}）` },
    { label: '今日', value: todayCount.value, note: '件' },
    { label: '📧 メール由来', value: fromMail.value, note: '件' },
]);

// これからの予定が残っているときだけレールのナビに件数を出す
const navBadges = computed(() => (upcoming.value ? { '/schedule': upcoming.value } : {}));

// 選択中だけ墨ベタ。非選択側は内側に1本だけ罫が出る（選択されているのは常に片方）
const tabClass = (active: boolean): string =>
    active ? 'bg-ink font-medium text-paper' : 'bg-white text-ink-2 hover:bg-bench';
</script>

<template>
    <Head title="予定表" />
    <AppLayout rail :nav-badges="navBadges">
        <template #status><StatusBar :items="status" /></template>

        <template #rail>
            <p class="text-[10px] tracking-[.1em] text-rail-muted">カレンダー連携</p>
            <p class="mt-[6px] text-[11px] leading-[18px] text-rail-fg">
                📅 を押したときだけ .ics を<br />
                ダウンロードします。外部カレンダーへの自動送信はしません。
            </p>
        </template>

        <section class="flex flex-wrap items-baseline justify-between gap-3 px-5 pt-[18px]">
            <h1 class="font-serif text-[24px] leading-[1.3] font-semibold text-ink">予定表</h1>
            <button type="button" class="rounded-[2px] bg-ink btn-md font-medium text-paper"
                :aria-expanded="showForm" @click="startCreate">{{ createLabel }}</button>
        </section>

        <div v-if="showForm" class="mt-[14px] px-5">
            <ScheduleForm :companies="companies" :kind-options="kindOptions" :editing="editing" @done="closeForm" />
        </div>

        <div class="mt-[14px] flex flex-wrap items-center gap-3 border-y border-rule bg-bench px-5 py-2">
            <div class="flex items-stretch overflow-hidden rounded-[2px] border border-rule-input bg-white">
                <button type="button" class="px-[13px] py-[5px] text-[12px]" :class="tabClass(view === 'list')"
                    :aria-pressed="view === 'list'" @click="view = 'list'">リスト</button>
                <button type="button" class="border-l border-rule px-[13px] py-[5px] text-[12px]"
                    :class="tabClass(view === 'calendar')" :aria-pressed="view === 'calendar'"
                    @click="view = 'calendar'">月カレンダー</button>
            </div>
            <span class="text-[11px] text-ink-3">同じ予定を、時系列で消化する用と、月の埋まり具合を見る用で切り替えます</span>
        </div>

        <ScheduleList v-if="view === 'list'" :groups="groups" @edit="startEdit" />
        <ScheduleCalendar v-else :calendar="calendar" @edit="startEdit" />
    </AppLayout>
</template>
