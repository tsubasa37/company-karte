<script setup lang="ts">
import { briefingToText } from '@/composables/useBriefingText';
import { useClipboardCopy } from '@/composables/useClipboardCopy';
import type { Briefing } from '@/types/company';
import { useForm } from '@inertiajs/vue3';

const props = defineProps<{
    companyId: number;
    briefing: Briefing;
    /**
     * 生成後にカルテ・面接記録が更新された場合の警告文（最新なら null）。
     * 日時の比較は PHP の BriefingStaleness が確定し、ここは表示するだけ。
     */
    staleReason?: string | null;
}>();

const form = useForm({});
const generate = () => form.post(`/companies/${props.companyId}/briefing`, { preserveScroll: true });

// 面接に持ち出す用のコピー（逆質問と同じ方式。失敗は握り潰さず表示する）
const { copiedKey, copyError, copy } = useClipboardCopy();
const copyBriefing = () => copy(briefingToText(props.briefing), 'briefing');
</script>

<template>
    <section class="mb-10">
        <div class="flex flex-wrap items-baseline justify-between gap-3 border-b-2 border-ink pb-[5px]">
            <h3 class="font-serif text-[19px] font-semibold text-ink">面接前ブリーフィング</h3>
            <!-- 操作ボタンは紙に出さない（カルテ印刷時。完成済みの karte/* 各節と同じ） -->
            <div class="flex flex-wrap items-baseline gap-2 print:hidden">
                <span v-if="briefing.generatedAt" class="font-mono text-[11px] tabular-nums text-ink-3">生成: {{ briefing.generatedAt }}</span>
                <button v-if="briefing.data" @click="copyBriefing"
                    class="rounded-[2px] border border-rule-input bg-surface btn-sm text-ink hover:bg-bench">
                    {{ copiedKey === 'briefing' ? 'コピーしました' : 'コピー' }}
                </button>
                <button v-if="!briefing.isGenerating" @click="generate" :disabled="form.processing"
                    class="rounded-[2px] bg-ink btn-sm font-medium text-paper disabled:opacity-50">
                    {{ briefing.data ? '再生成' : '生成する' }}
                </button>
            </div>
        </div>

        <!-- 生成後にカルテ・面接記録が動いた場合の警告（古い内容を最新と誤読しないため） -->
        <p v-if="staleReason"
            class="mt-[10px] border-l-[3px] border-check bg-check-bg px-[11px] py-[7px] text-[12px] leading-[19px] text-check-fg">
            {{ staleReason }}
        </p>
        <p v-if="copyError"
            class="mt-[10px] border-l-[3px] border-conflict bg-conflict-bg px-[11px] py-[7px] text-[12px] leading-[19px] text-conflict-fg">
            コピーに失敗しました（ブラウザがクリップボードへの書き込みを拒否）。本文を選択して手動でコピーしてください。
        </p>

        <p v-if="form.errors.briefing"
            class="mt-[10px] border-l-[3px] border-conflict bg-conflict-bg px-[11px] py-[7px] text-[12px] leading-[19px] text-conflict-fg">
            {{ form.errors.briefing }}
        </p>
        <p v-if="briefing.error"
            class="mt-[10px] border-l-[3px] border-conflict bg-conflict-bg px-[11px] py-[7px] text-[12px] leading-[19px] text-conflict-fg">
            前回の生成に失敗しました: {{ briefing.error }}
        </p>

        <p v-if="briefing.isGenerating" class="mt-[14px] text-[13px] leading-[21px] text-ink-2">生成中…（1〜2分かかります）</p>

        <template v-else-if="briefing.data">
            <ul class="mt-[14px] list-disc pl-[18px] font-serif text-[15px] leading-[27px] text-ink">
                <li v-for="(line, i) in briefing.data.summary_lines" :key="i">{{ line }}</li>
            </ul>

            <div class="mt-[22px] grid gap-[22px] md:grid-cols-2">
                <div>
                    <h4 class="border-b border-rule pb-[4px] font-serif text-[15px] font-semibold text-ink">未解決の論点</h4>
                    <ul class="mt-[10px] list-disc pl-[18px] font-serif text-[15px] leading-[26px] text-ink">
                        <li v-for="(x, i) in briefing.data.open_issues" :key="i">{{ x }}</li>
                    </ul>
                </div>
                <div>
                    <h4 class="border-b border-rule pb-[4px] font-serif text-[15px] font-semibold text-ink">逆質問トップ5</h4>
                    <ol class="mt-[10px] list-decimal pl-[20px] font-serif text-[15px] leading-[26px] text-ink">
                        <li v-for="(q, i) in briefing.data.top_questions" :key="i">{{ q }}</li>
                    </ol>
                </div>
            </div>

            <div class="mt-[22px]">
                <h4 class="border-b border-rule pb-[4px] font-serif text-[15px] font-semibold text-ink">突っ込まれそうな点と備え</h4>
                <ul class="mt-[10px] list-disc pl-[18px] font-serif text-[15px] leading-[26px] text-ink">
                    <li v-for="(w, i) in briefing.data.weak_points" :key="i">{{ w }}</li>
                </ul>
            </div>

            <p class="mt-[14px] text-[12px] leading-[19px] text-ink-3">{{ briefing.data.note }}</p>
        </template>

        <p v-else class="mt-[14px] text-[13px] leading-[21px] text-ink-2">
            カルテから「面接直前に読む1ページ」（要点・未解決の論点・逆質問トップ5・突っ込まれ対策）を生成します。
        </p>
    </section>
</template>
