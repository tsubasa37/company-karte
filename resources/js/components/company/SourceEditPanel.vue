<script setup lang="ts">
import type { CompanyRef, Option } from '@/types/company';
import { useForm } from '@inertiajs/vue3';

const props = defineProps<{
    sourceId: number;
    sourceType: string;
    companyId: number;
    sourceTypeOptions: Option[];
    companyOptions: CompanyRef[];
    /** 面接メモのときだけ本文（編集を許可する種別。他種別は null） */
    interviewBody: string | null;
}>();

// 開閉はページ側が持つ（「種別・企業を変更」は見出し横の操作ボタン群にまとめている）
const open = defineModel<boolean>('open', { default: false });

// 種別・企業の付け替えと面接メモ本文の編集は1つの PATCH に寄せる（派生データの作り直しはサーバ側）
const form = useForm({
    type: props.sourceType,
    company_id: props.companyId,
    raw_text: props.interviewBody,
});

const submit = () =>
    form.patch(`/sources/${props.sourceId}`, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
</script>

<template>
    <form v-if="open" class="mt-5 border border-rule-input bg-surface p-4" @submit.prevent="submit">
        <div class="border-b border-rule pb-2 font-serif text-[15px] font-semibold text-ink">
            種別・企業を変更{{ interviewBody !== null ? '／本文を編集' : '' }}
        </div>

        <div class="mt-3 flex flex-wrap gap-4">
            <label class="text-[11px] text-ink-2">
                種別
                <select v-model="form.type" class="mt-1 block w-44 text-[13px] text-ink">
                    <option v-for="o in sourceTypeOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
            </label>
            <label class="text-[11px] text-ink-2">
                投入先の企業
                <select v-model="form.company_id" class="mt-1 block w-68 max-w-full text-[13px] text-ink">
                    <option v-for="c in companyOptions" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </label>
        </div>

        <p class="mt-3 text-[12px] leading-5 text-ink-2">
            変更すると、労働条件の抽出と口コミの分割は変更前・変更後の企業の両方でやり直されます
            （反映にはキューの処理時間がかかります）。本文の再クリーニングは行いません。
        </p>

        <label v-if="interviewBody !== null" class="mt-[14px] block text-[11px] text-ink-2">
            面接メモの本文（編集前の本文は変更履歴に退避されます）
            <textarea v-model="form.raw_text" rows="10"
                class="mt-[5px] block w-full text-[13px] leading-[22px] text-ink" />
        </label>

        <p v-if="form.errors.type" class="mt-[10px] text-[12px] text-conflict">{{ form.errors.type }}</p>
        <p v-if="form.errors.company_id" class="mt-[10px] text-[12px] text-conflict">{{ form.errors.company_id }}</p>
        <p v-if="form.errors.raw_text" class="mt-[10px] text-[12px] text-conflict">{{ form.errors.raw_text }}</p>

        <div class="mt-[14px] flex gap-2">
            <button type="submit" :disabled="form.processing"
                class="rounded-[2px] bg-ink btn-md font-medium text-paper disabled:opacity-50">
                変更する
            </button>
            <button type="button" @click="open = false"
                class="rounded-[2px] border border-rule-input bg-white btn-md text-ink hover:bg-bench">
                やめる
            </button>
        </div>
    </form>
</template>
