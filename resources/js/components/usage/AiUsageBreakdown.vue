<script setup lang="ts">
import { useAiUsageFormat } from '@/composables/useAiUsageFormat';
import type { AiCompanyUsage, AiModelUsage, AiPurposeUsage } from '@/types/ai-usage';
import { Link } from '@inertiajs/vue3';

defineProps<{
    byPurpose: AiPurposeUsage[];
    byCompany: AiCompanyUsage[];
    byModel: AiModelUsage[];
}>();

const { cost } = useAiUsageFormat();

// 費用 null の意味は実行先で違う。サブスク実行は「請求が無いのが正常」で、
// 実行先別の一覧と同じく「サブスク」と出す（API 系の「—」= 出せなかった、と読み分けられるようにする）
const costLabel = (row: { isSubprocess: boolean; costMicroUsd: number | null }): string =>
    row.isSubprocess && row.costMicroUsd === null ? 'サブスク' : cost(row.costMicroUsd);
</script>

<template>
    <div class="grid gap-6 md:grid-cols-2">
        <div>
            <h3 class="mb-1.5 border-b border-ink pb-1 font-serif text-[14px] font-semibold text-ink">用途別</h3>
            <table class="w-full text-[13px] tabular-nums">
                <thead>
                    <tr class="border-b border-rule text-left text-[11px] text-ink-3">
                        <th scope="col" class="py-1 font-medium">用途</th>
                        <th scope="col" class="py-1 text-right font-medium">実行</th>
                        <th scope="col" class="py-1 text-right font-medium">失敗</th>
                        <th scope="col" class="py-1 text-right font-medium">推定費用</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in byPurpose" :key="`${p.provider}-${p.purpose}`"
                        class="border-b border-rule-thin last:border-0">
                        <td class="py-1.5 text-ink">
                            {{ p.label }}
                            <!-- ボタンを押していないのに走る分は、気づかない消費なので明示する -->
                            <span v-if="!p.isExplicit"
                                class="ml-1.5 border-l-2 border-check pl-[5px] text-[11px] text-check">自動</span>
                            <span class="ml-1.5 text-[11px] text-ink-3">{{ p.providerLabel }}</span>
                        </td>
                        <td class="py-1.5 text-right font-mono text-ink">{{ p.calls }}</td>
                        <td class="py-1.5 text-right font-mono" :class="p.failed > 0 ? 'text-conflict' : 'text-ink-3'">
                            {{ p.failed }}
                        </td>
                        <td class="py-1.5 text-right text-ink">{{ costLabel(p) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div>
            <h3 class="mb-1.5 border-b border-ink pb-1 font-serif text-[14px] font-semibold text-ink">モデル別</h3>
            <table class="w-full text-[13px] tabular-nums">
                <thead>
                    <tr class="border-b border-rule text-left text-[11px] text-ink-3">
                        <th scope="col" class="py-1 font-medium">モデル</th>
                        <th scope="col" class="py-1 text-right font-medium">実行</th>
                        <th scope="col" class="py-1 text-right font-medium">推定費用</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="m in byModel" :key="`${m.provider}-${m.model ?? 'default'}`"
                        class="border-b border-rule-thin last:border-0">
                        <td class="py-1.5 text-ink">
                            {{ m.model ?? '（実行先の既定モデル）' }}
                            <span class="ml-1.5 text-[11px] text-ink-3">{{ m.providerLabel }}</span>
                        </td>
                        <td class="py-1.5 text-right font-mono text-ink">{{ m.calls }}</td>
                        <td class="py-1.5 text-right text-ink">{{ costLabel(m) }}</td>
                    </tr>
                </tbody>
            </table>

            <template v-if="byCompany.length">
                <h3 class="mt-[18px] mb-1.5 border-b border-ink pb-1 font-serif text-[14px] font-semibold text-ink">
                    企業別（多い順）
                </h3>
                <ul class="flex flex-wrap gap-x-4 gap-y-1 text-[13px]">
                    <li v-for="c in byCompany" :key="c.id ?? 'deleted'">
                        <Link v-if="c.id !== null" :href="`/companies/${c.id}`"
                            class="text-ink underline underline-offset-[3px] hover:text-conflict">{{ c.name }}</Link>
                        <!-- 企業に紐づかない実行・削除済み企業のバケツはリンク先が無い -->
                        <span v-else class="text-ink-2">{{ c.name }}</span>
                        <span class="ml-1 font-mono text-ink-3 tabular-nums">{{ c.calls }}回</span>
                    </li>
                </ul>
            </template>
        </div>
    </div>
</template>
