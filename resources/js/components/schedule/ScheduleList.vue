<script setup lang="ts">
import ScheduleItem from '@/components/schedule/ScheduleItem.vue';
import EmptyNote from '@/components/shell/EmptyNote.vue';
import type { ScheduleEvent, ScheduleGroup } from '@/types/schedule';

// 区分（今週/来週/それ以降/過去）の振り分けと並び順は PHP(BuildSchedulePageAction) で確定済み
defineProps<{ groups: ScheduleGroup[] }>();

const emit = defineEmits<{ edit: [ScheduleEvent] }>();
</script>

<template>
    <EmptyNote v-if="!groups.length">
        予定はまだありません。「予定を追加」から登録してください。
    </EmptyNote>
    <!-- 作業台型は本文に左右余白が無いので、この節が自分で px-5 を持つ -->
    <div v-else class="px-5 pt-1 pb-6">
        <section v-for="group in groups" :key="group.key" class="mt-5">
            <h2 class="mb-[2px] flex items-baseline gap-2 border-b-2 border-ink pb-[5px] font-serif text-[15px] font-semibold text-ink">
                {{ group.label }}
                <span class="font-mono text-[12px] font-medium tabular-nums text-ink-3">{{ group.events.length }}件</span>
            </h2>
            <!-- 行の区切りは ScheduleItem 自身の下罫。ここで行間を空けると罫が離れる -->
            <ScheduleItem v-for="event in group.events" :key="event.id" :event="event"
                @edit="emit('edit', $event)" />
        </section>
    </div>
</template>
