<script setup lang="ts">
import type { Option } from '@/types/company';
import type { ScheduleCompanyOption, ScheduleEvent } from '@/types/schedule';
import { useForm } from '@inertiajs/vue3';
import { useId, watch } from 'vue';

const props = defineProps<{
    kindOptions: Option[];
    /** 予定表では選択させる。企業ページでは固定するので渡さない */
    companies?: ScheduleCompanyOption[];
    /** 企業ページから使うときの固定企業 */
    fixedCompanyId?: number;
    /** 指定があれば編集モード（未指定は新規登録） */
    editing?: ScheduleEvent | null;
}>();

const emit = defineEmits<{ done: [] }>();

const uid = useId();
const fid = (key: string) => `${uid}-${key}`;

const blank = () => ({
    company_id: props.editing?.companyId ?? props.fixedCompanyId ?? null,
    kind: props.editing?.kind ?? 'interview',
    title: props.editing?.title ?? '',
    starts_at: props.editing?.startsAt ?? '',
    duration_minutes: props.editing?.durationMinutes ?? null,
    place: props.editing?.place ?? '',
    note: props.editing?.note ?? '',
});

const form = useForm(blank());

// 「編集」を押すたびに対象が差し替わるため、フォームの中身も追随させる
watch(() => props.editing, () => form.defaults(blank()).reset(), { deep: true });

const save = () => {
    const options = { preserveScroll: true, onSuccess: () => { form.reset(); emit('done'); } };
    if (props.editing) {
        form.put(`/schedule/${props.editing.id}`, options);
    } else {
        form.post('/schedule', options);
    }
};
</script>

<template>
    <!-- 入力欄の枠・角丸・フォーカスは app.css の base 層が持つので、ここでは幅と字送りだけ指定する -->
    <form class="border border-rule-input bg-surface p-4" @submit.prevent="save">
        <h3 class="mb-3 border-b border-rule pb-[7px] font-serif text-[15px] font-semibold text-ink">
            {{ editing ? '予定を編集' : '予定を追加' }}
        </h3>
        <div class="grid gap-[14px] sm:grid-cols-2">
            <div v-if="companies">
                <label :for="fid('company')" class="mb-1 block text-[11px] text-ink-3">企業（必須）</label>
                <select :id="fid('company')" v-model="form.company_id" required class="w-full text-[13px]">
                    <option :value="null">選択してください</option>
                    <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <p v-if="form.errors.company_id" class="mt-1 text-[11px] text-conflict">{{ form.errors.company_id }}</p>
            </div>
            <div>
                <label :for="fid('kind')" class="mb-1 block text-[11px] text-ink-3">種別</label>
                <select :id="fid('kind')" v-model="form.kind" class="w-full text-[13px]">
                    <option v-for="o in kindOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
            </div>
            <div>
                <label :for="fid('starts-at')" class="mb-1 block text-[11px] text-ink-3">開始日時（必須）</label>
                <input :id="fid('starts-at')" v-model="form.starts_at" type="datetime-local" required
                    class="w-full text-[13px]" />
                <p v-if="form.errors.starts_at" class="mt-1 text-[11px] text-conflict">{{ form.errors.starts_at }}</p>
            </div>
            <div>
                <label :for="fid('title')" class="mb-1 block text-[11px] text-ink-3">補足（1次・最終など）</label>
                <input :id="fid('title')" v-model="form.title" type="text" placeholder="2次"
                    class="w-full text-[13px]" />
            </div>
            <div>
                <label :for="fid('duration')" class="mb-1 block text-[11px] text-ink-3">所要時間（分・未入力は60分）</label>
                <input :id="fid('duration')" v-model.number="form.duration_minutes" type="number" min="1" max="1440"
                    class="w-full text-[13px] tabular-nums" />
                <p v-if="form.errors.duration_minutes" class="mt-1 text-[11px] text-conflict">{{ form.errors.duration_minutes }}</p>
            </div>
            <div>
                <label :for="fid('place')" class="mb-1 block text-[11px] text-ink-3">場所・URL</label>
                <input :id="fid('place')" v-model="form.place" type="text" placeholder="https://… / 本社5F"
                    class="w-full text-[13px]" />
                <p v-if="form.errors.place" class="mt-1 text-[11px] text-conflict">{{ form.errors.place }}</p>
            </div>
        </div>
        <label :for="fid('note')" class="mt-[14px] mb-1 block text-[11px] text-ink-3">メモ（面接官・持ち物など）</label>
        <textarea :id="fid('note')" v-model="form.note" rows="2" class="w-full text-[13px] leading-[21px]" />
        <div class="mt-[14px] flex gap-2">
            <button type="submit" :disabled="form.processing"
                class="rounded-[2px] bg-ink btn-md font-medium text-paper disabled:opacity-50">
                {{ editing ? '更新する' : '追加する' }}
            </button>
            <button v-if="editing" type="button"
                class="rounded-[2px] border border-rule-input bg-white btn-md text-ink hover:bg-bench"
                @click="emit('done')">キャンセル</button>
        </div>
    </form>
</template>
