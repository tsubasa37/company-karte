<script setup lang="ts">
import HelpTip from '@/components/HelpTip.vue';
import AiDecisionForm from '@/components/settings/AiDecisionForm.vue';
import { HELP } from '@/constants/help';
import type { AiDecisionStatus, AiDecisionTestResult } from '@/types/ai';
import { computed } from 'vue';

const props = defineProps<{ decision: AiDecisionStatus; testResult: AiDecisionTestResult | null }>();

// 見出しのチップと末尾の行は保存済み（いま実際に走る）の状態。選択中の値はフォーム側に出る
const saved = computed(() => props.decision.providers.find((p) => p.provider === props.decision.provider));
</script>

<template>
    <div>
        <h3
            class="mt-6 flex items-center gap-1.5 border-b border-ink pb-1 font-serif text-[15px] font-semibold text-ink">
            判定エンジン（機械判定）
            <HelpTip :help="HELP.aiDecision" />
            <span v-if="saved"
                class="ml-1 border-l-2 border-match bg-match-bg px-[7px] py-px font-sans text-[11px] font-normal text-match-fg">{{
                    saved.label }}</span>
            <span v-else
                class="ml-1 border-l-2 border-rule-input bg-canvas px-[7px] py-px font-sans text-[11px] font-normal text-ink-3">オフ</span>
        </h3>
        <p class="mt-2 text-[12px] leading-5 text-ink-2">
            確率つきの型付き判定だけを担う第 2 の実行先。分析のたびに口コミ全件を主張ごとに機械判定し（矛盾・整合・言及なし）、主張検証テーブルに件数を足す。文章は作らない
        </p>

        <div class="mt-3 border border-rule-input bg-surface p-4">
            <AiDecisionForm :decision="decision" :test-result="testResult" />
        </div>

        <p class="mt-3 text-[11px] leading-[17px] text-ink-3">
            いまの判定エンジン（保存済み）:
            <template v-if="saved">{{ saved.label }}（{{ saved.modelDecision ?? 'モデル未設定' }}）</template>
            <template v-else>オフ</template>
        </p>
    </div>
</template>
