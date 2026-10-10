<script setup lang="ts">
import ScheduleForm from '@/components/schedule/ScheduleForm.vue';
import ScheduleItem from '@/components/schedule/ScheduleItem.vue';
import EmptyNote from '@/components/shell/EmptyNote.vue';
import type { Option } from '@/types/company';
import type { ScheduleEvent } from '@/types/schedule';
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';

// この企業の予定（時系列）。予定表ページと同じ登録先（/schedule）に投げる
defineProps<{
    companyId: number;
    events: ScheduleEvent[];
    kindOptions: Option[];
}>();

const showForm = ref(false);
const editing = ref<ScheduleEvent | null>(null);

const startEdit = (event: ScheduleEvent) => {
    editing.value = event;
    showForm.value = true;
};

// 編集中に押された場合は「新規追加に切り替える」であって、閉じる操作ではない
const startCreate = () => {
    const wasEditing = editing.value !== null;
    editing.value = null;
    showForm.value = wasEditing || !showForm.value;
};

const closeForm = () => {
    editing.value = null;
    showForm.value = false;
};
</script>

<template>
    <section>
        <div class="flex flex-wrap items-center justify-between gap-2 border-b-2 border-ink pb-[5px]">
            <h3 class="font-serif text-[19px] font-semibold text-ink">
                選考の予定
                <span class="ml-1 font-sans text-[11px] leading-4 font-normal text-ink-3">
                    1次・2次・最終を並べて残せます。近づくとダッシュボードに出ます
                </span>
            </h3>
            <div class="flex items-center gap-2">
                <Link href="/schedule"
                    class="text-[12px] text-ink-2 underline underline-offset-[3px] hover:text-conflict">予定表で見る</Link>
                <button type="button"
                    class="rounded-[2px] border border-rule-input bg-white btn-sm text-ink hover:bg-bench"
                    @click="startCreate">＋ 予定を追加</button>
            </div>
        </div>

        <div v-if="showForm" class="mt-[14px]">
            <ScheduleForm :kind-options="kindOptions" :fixed-company-id="companyId" :editing="editing"
                @done="closeForm" />
        </div>

        <EmptyNote v-if="!events.length">
            予定はまだ登録されていません。右上の「＋ 予定を追加」から面接・面談を登録してください。
        </EmptyNote>
        <!-- 行の区切りは ScheduleItem 自身の下罫。ここで行間を空けると罫が離れる -->
        <div v-else class="mt-1">
            <ScheduleItem v-for="event in events" :key="event.id" :event="event" hide-company
                @edit="startEdit" />
        </div>
    </section>
</template>
