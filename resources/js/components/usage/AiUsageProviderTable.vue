<script setup lang="ts">
import CodexRateLimitGauge from '@/components/CodexRateLimitGauge.vue';
import { useAiUsageFormat } from '@/composables/useAiUsageFormat';
import type { AiProviderUsage } from '@/types/ai-usage';

defineProps<{ providers: AiProviderUsage[] }>();

const { cost, tokens } = useAiUsageFormat();

// 費用を出せなかった理由は PHP が件数で確定済み。画面で判定し直さずそのまま使う
const costLabel = (row: AiProviderUsage): string => {
    // サブスク実行に請求は発生しない。参考換算は「API なら幾らだったか」の目安
    if (row.isSubprocess) {
        if (row.referenceCostMicroUsd === null) return 'サブスク';

        return `サブスク（参考換算 ${cost(row.referenceCostMicroUsd)}・請求なし）`;
    }
    if (row.costMicroUsd !== null) return cost(row.costMicroUsd);
    if (row.unpricedCalls > 0) return '単価未登録';
    if (row.untokenedCalls > 0) return 'トークン未取得';

    return '—';
};

/** トークンの内訳。10列の横スクロールをやめ、行内の帯に畳む */
const tokenCells = (row: AiProviderUsage): { label: string; value: string }[] => [
    { label: '入力', value: tokens(row.inputTokens) },
    { label: 'キャッシュ', value: tokens(row.cachedTokens) },
    { label: '出力', value: tokens(row.outputTokens) },
    { label: '推論', value: tokens(row.reasoningTokens) },
];
</script>

<template>
    <div class="border-t border-ink">
        <div v-for="row in providers" :key="row.provider" class="border-b border-rule-thin py-3"
            :class="row.calls === 0 ? 'text-ink-3' : 'text-ink'">
            <!-- 見出し行: 実行先 / N回 / 失敗 / 推定費用 -->
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <span class="flex flex-wrap items-baseline gap-3">
                    <span class="text-[13px] font-semibold">{{ row.label }}</span>
                    <span class="whitespace-nowrap text-[12px] text-ink-3">
                        <b class="font-mono tabular-nums" :class="row.calls === 0 ? 'text-ink-3' : 'text-ink'">{{
                            row.calls
                        }}</b>
                        回
                        <!-- 失敗は 0 でも出す（起きていないことが分かる） -->
                        <span :class="row.failed > 0 ? 'font-semibold text-conflict' : 'text-ink-3'">
                            / 失敗 <span class="font-mono tabular-nums">{{ row.failed }}</span>
                        </span>
                    </span>
                </span>
                <span class="text-right">
                    <span class="block text-[11px] text-ink-3">推定費用</span>
                    <span class="block text-[13px] font-semibold tabular-nums">{{ costLabel(row) }}</span>
                </span>
            </div>

            <!-- トークン帯。値が無い（実行先が返さなかった）ものは「—」のまま出す -->
            <div class="mt-[7px] flex flex-wrap gap-x-4 gap-y-1 text-[12px] text-ink-3">
                <span v-for="cell in tokenCells(row)" :key="cell.label" class="whitespace-nowrap">
                    {{ cell.label }}
                    <b class="font-mono tabular-nums" :class="row.calls === 0 ? 'text-ink-3' : 'text-ink-2'">{{
                        cell.value
                    }}</b>
                </span>
                <!-- 費用を出せなかった実行。0 でないなら推定費用が実費より小さい -->
                <span class="whitespace-nowrap" :class="row.unpricedCalls > 0 ? 'text-check' : 'text-ink-3'">
                    単価未登録 <b class="font-mono tabular-nums">{{ row.unpricedCalls }}</b>
                </span>
                <span class="whitespace-nowrap" :class="row.untokenedCalls > 0 ? 'text-check' : 'text-ink-3'">
                    トークン未取得 <b class="font-mono tabular-nums">{{ row.untokenedCalls }}</b>
                </span>
            </div>

            <!-- 換算できなかった実行があると参考換算は実際より小さい。数字だけを鵜呑みにさせない。
                 title 属性はキーボード・タッチ・読み上げから読めないので、その行の中に文として出す -->
            <p v-if="(row.referenceUnpricedCalls ?? 0) > 0" class="mt-2 text-[11px] leading-[18px] text-check-fg">
                {{ row.label }} の参考換算のうち {{ row.referenceUnpricedCalls }}
                件は単価表に無いモデルで換算できていません（表示額は実際より小さくなります）。
            </p>

            <!-- codex はサブスクの利用枠が消費の実体なので、行の中にゲージを出す -->
            <div v-if="row.rateLimit" class="mt-3">
                <CodexRateLimitGauge :rate-limit="row.rateLimit" />
            </div>
        </div>
    </div>
</template>
