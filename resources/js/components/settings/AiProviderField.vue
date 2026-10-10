<script setup lang="ts">
import type { AiOptionField } from '@/types/ai';

// ラベルは表の列見出し（分析・要約／抽出・判定）と行見出し（設定項目）に移した。
// 見出しは入力の名前にならないので、読み上げ用に label をそのまま aria-label で渡す。
// help も行見出し側に1回だけ出す（Tier ごとに同じ文が2回出ないように）
defineProps<{ field: AiOptionField; label: string; error?: string }>();

// 空文字は「未設定」。SaveAiProviderConfigAction が空の鍵を落とすので、その鍵は送信されない
const value = defineModel<string | number>({ required: true });
</script>

<template>
    <div>
        <select v-if="field.type === 'select'" v-model="value" :aria-label="label" class="w-full text-[13px]">
            <option value="">未設定（モデルの既定）</option>
            <option v-for="c in field.choices" :key="c.value" :value="c.value">{{ c.label }}</option>
        </select>
        <!-- 空欄のままなら v-model.number は空文字を残すので、未設定として送れる -->
        <input v-else-if="field.type === 'number'" v-model.number="value" type="number" :aria-label="label"
            :min="field.min ?? undefined" :max="field.max ?? undefined" :step="field.step ?? undefined"
            placeholder="未設定（モデルの既定）" class="w-full text-[13px] tabular-nums" />
        <!-- 未対応の型は別の入力に黙って落とさず、その旨を出す -->
        <p v-else class="text-[11px] text-conflict">この項目の型（{{ field.type }}）に画面が未対応です</p>
        <p v-if="error" class="mt-1 text-[11px] text-conflict">{{ error }}</p>
    </div>
</template>
