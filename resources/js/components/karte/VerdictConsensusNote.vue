<script setup lang="ts">
import { verdictLabel } from '@/constants/verdict';
import type { Verdict, VerdictConsensus } from '@/types/karte';
import { Link } from '@inertiajs/vue3';

defineProps<{ consensus: VerdictConsensus; verdict: Verdict }>();
</script>

<template>
    <!-- 1人で判定したランは内訳を出しても情報が増えないため表示しない -->
    <div v-if="consensus.panel_size > 1" class="text-[11px] leading-[17px]">
        <template v-if="consensus.aggregated">
            <p class="mt-[6px]" :class="consensus.is_unanimous ? 'text-ink-3' : 'text-check-fg'">
                {{ consensus.summary }}
            </p>
            <!--
                判定が割れた行は既定で開く。根拠列には代表1人ぶんの根拠しか出ないため、
                内訳が畳まれていると「整合と書いてあるのに判定は割れ」と食い違って見える
            -->
            <details :open="verdict === 'split'" class="mt-[5px]">
                <summary class="cursor-pointer text-ink-3 hover:text-ink">判定者ごとの意見</summary>
                <ul class="mt-[6px] flex list-none flex-col gap-2 p-0">
                    <li v-for="(o, i) in consensus.opinions" :key="i">
                        <span class="font-semibold text-ink">{{ o.label }}</span>
                        <span class="text-ink-2">: {{ verdictLabel(o.verdict) }}</span>
                        <p class="mt-[2px] text-ink-2">{{ o.rationale }}</p>
                        <!--
                            観点が違えば突き合わせた材料も違う。代表行の突合先だけでは
                            「公式データを見て矛盾と判定した意見」からその材料へ辿れない
                        -->
                        <p v-if="o.checked_against" class="mt-[2px] text-ink-3">突合先: {{ o.checked_against }}</p>
                        <div v-if="o.source_ids?.length" class="mt-[3px] flex flex-wrap gap-[5px]">
                            <Link v-for="id in o.source_ids" :key="id" :href="`/sources/${id}`"
                                class="rounded-[2px] border border-rule-input px-[5px] py-px font-mono text-[11px] text-ink-2 hover:text-conflict">
                                #{{ id }}
                            </Link>
                        </div>
                    </li>
                </ul>
            </details>
        </template>
        <!-- 合議できなかった行（どの主張への判定か特定できない）も黙って消さず理由を出す -->
        <p v-else class="mt-[6px] text-ink-3">
            {{ consensus.opinions[0]?.label }}の指摘（主張との対応が不明なため合議の対象外）
        </p>
    </div>
</template>
