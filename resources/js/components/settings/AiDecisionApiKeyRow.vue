<script setup lang="ts">
import type { AiDecisionProviderStatus } from '@/types/ai';

defineProps<{ target: AiDecisionProviderStatus | undefined }>();
</script>

<template>
    <!-- 見出しと値の 2 セルで、親のグリッドの 1 行になる -->
    <span class="font-semibold text-ink">APIキー</span>
    <!-- キーは .env に書く。画面では設定済みかと変数名だけを示す（値は受け取らない） -->
    <p v-if="target?.apiKeyEnvName" class="text-[12px] leading-5 text-ink-2">
        <span v-if="target.hasApiKey"
            class="border-l-2 border-match bg-match-bg px-[7px] py-px text-[11px] text-match-fg">設定済み</span>
        <span v-else
            class="border-l-2 border-check bg-check-bg px-[7px] py-px text-[11px] text-check-fg">未設定</span>
        <code class="ml-1 bg-canvas px-[5px] font-mono text-[11px] text-ink">{{ target.apiKeyEnvName }}</code>
        を .env に書きます（この画面では扱いません）
    </p>
    <p v-else class="text-[12px] text-ink-4">—</p>
</template>
