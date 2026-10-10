<script setup lang="ts">
import type { AiDecisionProviderStatus } from '@/types/ai';
import { computed, ref } from 'vue';

const props = defineProps<{
    id: string;
    models: AiDecisionProviderStatus['models'];
    placeholder: string;
}>();

const model = defineModel<string>({ required: true });

/** 一覧に無い名前を使っているときの選択肢。空文字（未設定）と区別するため実在しない値にする */
const OTHER = '__other__';

// 保存済みの値が候補に無ければ、最初から直接入力の状態で開く
const useCustom = ref(model.value !== '' && !props.models.some((m) => m.name === model.value));

// その他に切り替えても値は消さず、下の入力欄に今の名前を出して直せるようにする
const selected = computed({
    get: () => (useCustom.value ? OTHER : model.value),
    set: (value: string) => {
        useCustom.value = value === OTHER;

        if (value !== OTHER) {
            model.value = value;
        }
    },
});

// 判定の単価は 0.042 のように小さいので、生成側（小数2桁）より1桁多く出す
const optionLabel = (m: AiDecisionProviderStatus['models'][number]): string =>
    `${m.name} — 入力 $${m.inputUsd.toFixed(3)} / 出力 $${m.outputUsd.toFixed(3)}（100万トークンあたり）`;
</script>

<template>
    <div>
        <select :id="id" v-model="selected" class="w-full text-[13px]">
            <option value="">未設定</option>
            <option v-for="m in models" :key="m.name" :value="m.name">{{ optionLabel(m) }}</option>
            <option :value="OTHER">一覧に無いモデルを使う</option>
        </select>

        <input v-if="useCustom" v-model="model" type="text" :placeholder="placeholder" autocomplete="off"
            class="mt-1 w-full text-[13px]" aria-label="判定用モデル（モデル名を直接入力）" />
    </div>
</template>
