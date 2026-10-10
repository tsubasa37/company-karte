<script setup lang="ts">
defineProps<{ id: string; disabled: boolean; error?: string }>();

const checked = defineModel<boolean>({ required: true });
</script>

<template>
    <!-- 見出しと入力の 2 セルで、親のグリッドの 1 行になる -->
    <span class="font-semibold text-ink">材料選抜</span>
    <div>
        <label :for="id" class="flex items-baseline gap-2 text-[13px] text-ink">
            <input :id="id" v-model="checked" type="checkbox" :disabled="disabled" class="rounded-[2px] border-rule-input" />
            口コミの材料選抜に機械判定を使う
        </label>
        <p v-if="error" class="mt-1 text-[11px] text-conflict">{{ error }}</p>
        <!-- オフで保存すると Action が外すので、そのことを先に見せる（黙って外さない） -->
        <p v-if="disabled" class="mt-1 text-[11px] text-ink-3">判定エンジンがオフの間は使えません（オフで保存すると外れます）</p>
        <p class="mt-[5px] text-[11px] leading-[17px] text-ink-3">
            オンにすると、裏取り（P2）に渡す口コミを新しい順ではなく主張に関係する順に選びます。24,000 字に入り切らない企業で効きます。口コミ品質処理（P4）は新しい順のままです
        </p>
    </div>
</template>
