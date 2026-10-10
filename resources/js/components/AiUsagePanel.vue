<script setup lang="ts">
import EmptyNote from '@/components/shell/EmptyNote.vue';
import AiUsageBreakdown from '@/components/usage/AiUsageBreakdown.vue';
import AiUsageProviderTable from '@/components/usage/AiUsageProviderTable.vue';
import { useAiUsageFormat } from '@/composables/useAiUsageFormat';
import type { AiUsageData } from '@/types/ai-usage';
import { computed } from 'vue';

const props = defineProps<{ aiUsage: AiUsageData }>();

const { cost } = useAiUsageFormat();

// null の理由は PHP が件数で確定済み。「出せなかった」を 0 円と読ませない
const autoCostLabel = computed((): string => {
    if (props.aiUsage.autoCostMicroUsd !== null) return cost(props.aiUsage.autoCostMicroUsd);
    if (props.aiUsage.autoUnpricedCalls > 0) return '単価未登録';
    if (props.aiUsage.autoUntokenedCalls > 0) return 'トークン未取得';

    return '費用なし';
});
</script>

<template>
    <section>
        <h2 class="border-b-2 border-ink pb-[5px] font-serif text-[19px] font-semibold text-ink">
            AI の使用状況（直近{{ aiUsage.days }}日）
        </h2>
        <p class="mt-2.5 text-[12px] leading-5 text-ink-2">
            実行先ごとの記録です。API の推定費用は応答のトークン数 × コードで管理している単価表による見積もりで、
            請求の正は各社のダッシュボードです。codex はサブスクの利用枠を消費します。
            <!-- 移行前の記録はトークン列が NULL。実行先が返さなかったのではなく、記録の仕組みが無かっただけ -->
            トークンと費用の記録はマルチプロバイダ対応より後の実行から貯まるため、それ以前の実行は「未取得」に数えられます。
        </p>

        <div class="mt-3.5">
            <AiUsageProviderTable :providers="aiUsage.providers" />
        </div>

        <!-- 期間外の記録は集計に入らない。「記録が無い」ではなく「この期間の実行が無い」と出す -->
        <EmptyNote v-if="aiUsage.calls === 0">
            直近{{ aiUsage.days }}日の実行はありません。
            <span v-if="aiUsage.lastAt">最終実行: <span class="font-mono tabular-nums">{{ aiUsage.lastAt }}</span></span>
        </EmptyNote>
        <template v-else>
            <p class="mt-3 text-[13px] text-ink">
                <!-- 押していないのに走る分。抽出・判定を API にすると気づかないまま課金される -->
                うち自動実行分（口コミ分割・条件抽出・メール抽出）:
                <b class="font-mono tabular-nums">{{ aiUsage.autoCalls }}</b> 回 /
                <b class="tabular-nums">{{ autoCostLabel }}</b>
                <span v-if="aiUsage.lastAt" class="ml-3 text-[11px] text-ink-3">最終実行:
                    <span class="font-mono tabular-nums">{{ aiUsage.lastAt }}</span></span>
            </p>

            <!-- 内訳は常時表示（開かないと分からない状態を作らない） -->
            <div class="mt-[18px]">
                <AiUsageBreakdown :by-purpose="aiUsage.byPurpose" :by-company="aiUsage.byCompany"
                    :by-model="aiUsage.byModel" />
            </div>
        </template>
    </section>
</template>
