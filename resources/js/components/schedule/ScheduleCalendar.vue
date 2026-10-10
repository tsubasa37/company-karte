<script setup lang="ts">
import type { ScheduleCalendar, ScheduleEvent } from '@/types/schedule';
import { Link } from '@inertiajs/vue3';

// 7列グリッド・今日判定・当月かどうかは PHP(BuildSchedulePageAction) で組み立て済み
defineProps<{ calendar: ScheduleCalendar }>();

const emit = defineEmits<{ edit: [ScheduleEvent] }>();
</script>

<template>
    <!-- 作業台型は本文に左右余白が無いので、この節が自分で px-5 を持つ -->
    <div class="px-5 pt-[18px] pb-6">
        <div class="mb-3 flex items-center justify-between">
            <Link :href="`/schedule?month=${calendar.prevMonth}`" preserve-state
                class="rounded-[2px] border border-rule-input bg-white btn-sm text-ink hover:bg-bench">← 前月</Link>
            <h2 class="font-serif text-[17px] font-semibold text-ink">{{ calendar.label }}</h2>
            <Link :href="`/schedule?month=${calendar.nextMonth}`" preserve-state
                class="rounded-[2px] border border-rule-input bg-white btn-sm text-ink hover:bg-bench">翌月 →</Link>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] table-fixed border-collapse text-[11px]">
                <thead>
                    <tr>
                        <!-- 日曜だけ矛盾色、土曜は薄墨（赤/青の対はやめる） -->
                        <th v-for="(w, i) in calendar.weekdays" :key="w" scope="col"
                            class="border border-rule bg-bench p-[5px] font-normal"
                            :class="i === 0 ? 'text-conflict' : i === 6 ? 'text-ink-2' : 'text-ink-3'">
                            {{ w }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(week, wi) in calendar.weeks" :key="wi">
                        <td v-for="day in week" :key="day.date" class="h-24 border border-rule p-1 align-top"
                            :class="day.isToday ? 'bg-group-head' : day.isCurrentMonth ? 'bg-surface' : 'bg-bench'">
                            <div class="mb-1 font-mono text-[11px] tabular-nums"
                                :class="day.isToday ? 'font-bold text-ink' : day.isCurrentMonth ? 'text-ink-2' : 'text-ink-4'">
                                {{ day.day }}
                            </div>
                            <!-- 予定チップ。青の塗りはやめ、墨の左罫＋紙より少し濃い地で示す -->
                            <button v-for="event in day.events" :key="event.id" type="button"
                                class="mb-0.5 block w-full truncate border-l-2 border-ink bg-canvas px-1 py-0.5 text-left text-[10px] text-ink hover:bg-group-head"
                                :title="`${event.companyName} ${event.displayTitle} ${event.time}`"
                                @click="emit('edit', event)">
                                {{ event.time }} {{ event.companyName }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
