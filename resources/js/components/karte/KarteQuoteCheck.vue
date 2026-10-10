<script setup lang="ts">
import HelpTip from '@/components/HelpTip.vue';
import type { P1Claims } from '@/types/karte';
import { HELP } from '@/constants/help';

// claimCount は PHP が確定した P1 主張数（0件除外時に「全件一致」と言うための分母）
defineProps<{ p1: P1Claims | null; claimCount: number }>();
</script>

<template>
    <!-- 除外あり・0件のどちらも同じ節として見出しの下に出す（P1未実行のときだけ節ごと出さない） -->
    <section v-if="p1 && (p1.dropped_claims_count > 0 || claimCount > 0)">
        <h2 class="flex items-center gap-[6px] border-b-2 border-ink pb-[5px] font-serif text-[19px] font-semibold text-ink">
            引用の実在チェック
            <HelpTip :help="HELP.quoteCheck" />
        </h2>
        <div v-if="p1.dropped_claims_count > 0"
            class="mt-[10px] border-l-[3px] border-check bg-check-bg px-[13px] py-[10px]">
            <p class="text-[12px] leading-[20px] text-check-fg">
                AIが挙げた主張のうち、<b>引用が求人票の原文に見つからなかった {{ p1.dropped_claims_count }}件</b>を分析から除外しました
                （原文にない内容を根拠にしないための機械的チェックです）。
            </p>
            <ul class="mt-[7px] list-disc pl-4 text-[12px] leading-[20px] text-check">
                <li v-for="(d, i) in p1.dropped_claims" :key="i">
                    {{ d.claim }}<span v-if="d.quote" class="text-ink-3">（引用: {{ d.quote }}）</span>
                </li>
            </ul>
        </div>
        <!-- 0件は「チェックが走って全件通過した」ことを明示（P1未実行と区別する） -->
        <p v-else class="mt-[10px] border-l-[3px] border-match bg-match-bg px-3 py-2 text-[12px] leading-[20px] text-match-fg">
            抽出した{{ claimCount }}件の主張はすべて求人票の原文と一致しました。
        </p>
    </section>
</template>
