<script setup lang="ts">
import type { AiModelOption } from '@/types/ai';
import { computed, ref } from 'vue';

const props = defineProps<{
    /** 表の列見出しに出す名前。見出しは入力の名前にならないので aria-label にも使う */
    label: string;
    models: AiModelOption[];
    placeholder: string;
    /** サブスク実行（codex）。単価表を持たないので候補も費用も出ない */
    isSubprocess: boolean;
    error?: string;
}>();

const model = defineModel<string>({ required: true });

/** 一覧に無い名前を使っているときの選択肢。空文字（未設定）と区別するため実在しない値にする */
const OTHER = '__other__';

// 保存済みの値が候補に無ければ、最初から直接入力の状態で開く
const useCustom = ref(model.value !== '' && !props.models.some((m) => m.name === model.value));

// セレクトは「未設定 / 候補 / その他」の3状態。その他に切り替えても値は消さず、
// 下の入力欄に今の名前を出して直せるようにする（選び直しただけで設定が消えないように）
const selected = computed({
    get: () => (useCustom.value ? OTHER : model.value),
    set: (value: string) => {
        useCustom.value = value === OTHER;

        if (value !== OTHER) {
            model.value = value;
        }
    },
});

/** 選択肢のラベル。1社あたりの目安を添えて、選ぶ場で比べられるようにする */
const optionLabel = (m: AiModelOption): string =>
    m.perCompanyJpy === null ? m.name : `${m.name} — 1社あたり約 ${m.perCompanyJpy.toLocaleString()}円`;

/** 単価を出せるのは候補から選んでいるときだけ（直接入力中は単価表に無い前提） */
const current = computed(() => (useCustom.value ? null : (props.models.find((m) => m.name === model.value) ?? null)));
</script>

<template>
    <div>
        <!-- codex は単価表を持たないので候補が無い。名前を直接入力する形に固定する -->
        <select v-if="!isSubprocess" v-model="selected" :aria-label="label" class="w-full text-[13px]">
            <option value="">未設定（実行先に選ぶと保存を求められます）</option>
            <option v-for="m in models" :key="m.name" :value="m.name">{{ optionLabel(m) }}</option>
            <option :value="OTHER">一覧に無いモデルを使う</option>
        </select>

        <input v-if="isSubprocess || useCustom" v-model="model" type="text" :placeholder="placeholder"
            autocomplete="off" class="w-full text-[13px]" :class="{ 'mt-1': !isSubprocess }"
            :aria-label="isSubprocess ? label : `${label}（モデル名を直接入力）`" />

        <p v-if="current" class="mt-[5px] text-[11px] leading-[17px] text-ink-2">
            入力 ${{ current.inputUsd.toFixed(2) }} / 出力 ${{ current.outputUsd.toFixed(2) }}（100万トークンあたり）
            <span v-if="current.hasBands" class="text-check">・長い入力では単価が上がります</span>
        </p>
        <p v-else-if="isSubprocess" class="mt-[5px] text-[11px] leading-[17px] text-ink-2">
            サブスクで走るため API 課金はありません（ChatGPT の利用枠を消費します）。空欄なら codex の既定モデル
        </p>
        <p v-else-if="useCustom" class="mt-[5px] text-[11px] leading-[17px] text-check">
            単価表に無いモデルです。動きますが、推定費用は「単価未登録」になります
        </p>

        <p v-if="error" class="mt-1 text-[11px] text-conflict">{{ error }}</p>
    </div>
</template>
