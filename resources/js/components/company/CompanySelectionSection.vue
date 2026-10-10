<script setup lang="ts">
import type { CompanyInfoForm } from '@/types/company';
import type { Option } from '@/types/company';
import { useId } from 'vue';

// form は親（TabInfo）の useForm をそのまま受け取り、入力欄だけを分担する
defineProps<{
    form: CompanyInfoForm;
    statusOptions: Option[];
}>();

const uid = useId();
const fid = (key: string) => `${uid}-${key}`;
</script>

<template>
    <section>
        <h3 class="border-b-2 border-ink pb-[5px] font-serif text-[19px] font-semibold text-ink">選考</h3>
        <label :for="fid('status')" class="mt-[14px] mb-[5px] block text-[11px] leading-4 text-ink-3">選考ステータス</label>
        <select :id="fid('status')" v-model="form.status" class="mb-[14px] w-full text-[13px] sm:w-64">
            <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>

        <div class="mb-[14px]">
            <label :for="fid('offer-deadline')" class="mb-[5px] block text-[11px] leading-4 text-ink-3">
                オファー回答期限 — 近づくとダッシュボードに出ます
            </label>
            <input :id="fid('offer-deadline')" v-model="form.offer_deadline" type="date"
                class="w-full text-[13px] tabular-nums sm:w-64" />
        </div>

        <label :for="fid('selection-notes')" class="mb-[5px] block text-[11px] leading-[17px] text-ink-3">
            選考メモ（印象・雑感・次に確認したいこと）。
            面接での回答そのものは「ソース」タブの<span class="font-medium text-ink">面接記録</span>に書くと、
            求人票の主張の検証材料として強く扱われます
        </label>
        <textarea :id="fid('selection-notes')" v-model="form.selection_notes" rows="6"
            class="w-full text-[13px] leading-[22px]" placeholder="印象・雑感・新たな疑問…" />
    </section>
</template>
