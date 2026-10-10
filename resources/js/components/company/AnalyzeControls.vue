<script setup lang="ts">
import HelpTip from '@/components/HelpTip.vue';
import { HELP } from '@/constants/help';
import type { PanelSizeOption, RunMeta } from '@/types/company';
import { useForm } from '@inertiajs/vue3';

const props = defineProps<{
    companyId: number;
    run: RunMeta | null;
    hasKarte: boolean;
    isBlocked: boolean;
    panelSizeOptions: PanelSizeOption[];
}>();

// 人数は設定ではなく実行ごとの選択。初期値は直近ランと同じ人数にして、
// 同じ条件での再分析をやり直しやすくする（前回が無ければ1人）
const form = useForm({ panel_size: props.run?.panelSize ?? 1 });

const analyze = () => form.post(`/companies/${props.companyId}/analyze`, { preserveScroll: true });
</script>

<template>
    <div class="print:hidden">
        <div class="flex items-center gap-2">
            <!-- 裏取り(P2)の判定者の人数。観点の異なる判定者が独立に判定し、PHP側が多数決で確定する -->
            <select v-model.number="form.panel_size" :disabled="form.processing || run?.isRunning"
                class="text-[13px] disabled:opacity-50" aria-label="裏取り判定の人数">
                <option v-for="o in panelSizeOptions" :key="o.value" :value="o.value">裏取り {{ o.label }}</option>
            </select>
            <HelpTip :help="HELP.panelSize" />
            <button @click="analyze" :disabled="form.processing || run?.isRunning || isBlocked"
                :aria-busy="run?.isRunning"
                class="rounded-[2px] bg-ink btn-md font-medium text-paper disabled:opacity-50">
                {{ run?.isRunning ? '分析中…' : (hasKarte ? '再分析' : '分析実行') }}
            </button>
        </div>

        <!-- 人数を増やすとAIの実行回数と消費も人数倍になることを、押す前に出す -->
        <p v-if="form.panel_size > 1" class="mt-[5px] text-right text-[11px] text-ink-3">
            裏取りを{{ form.panel_size }}人で判定します（AIの実行回数・消費も{{ form.panel_size }}倍）
        </p>
        <p v-if="form.errors.panel_size" class="mt-2 text-right text-[12px] text-conflict">{{ form.errors.panel_size }}</p>
        <p v-if="form.errors.analyze" class="mt-2 text-right text-[12px] text-conflict">{{ form.errors.analyze }}</p>
    </div>
</template>
