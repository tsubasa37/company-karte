<script setup lang="ts">
defineProps<{ id: string; disabled: boolean; error?: string }>();

const checked = defineModel<boolean>({ required: true });
</script>

<template>
    <!-- 見出しと入力の 2 セルで、親のグリッドの 1 行になる -->
    <span class="font-semibold text-ink">面接メモ</span>
    <div>
        <label :for="id" class="flex items-baseline gap-2 text-[13px] text-ink">
            <input :id="id" v-model="checked" type="checkbox" :disabled="disabled" class="rounded-[2px] border-rule-input" />
            面接メモを判定エンジンに送り、論点の消し込みを機械判定にする
        </label>
        <p v-if="error" class="mt-1 text-[11px] text-conflict">{{ error }}</p>
        <!-- オフで保存すると Action が外すので、そのことを先に見せる（黙って外さない） -->
        <p v-if="disabled" class="mt-1 text-[11px] text-ink-3">判定エンジンがオフの間は使えません（オフで保存すると外れます）</p>
        <p class="mt-[5px] border-l-[3px] border-check bg-check-bg px-[9px] py-1 text-[11px] leading-[17px] text-check-fg">
            面接メモは最も個人的なデータです。オンにすると、面接メモ本文（先頭 8,000 字）と追跡中の論点が TypeSafe AI に送られます。オフなら従来どおり、抽出・判定の実行先（LLM）で判定します
        </p>
    </div>
</template>
