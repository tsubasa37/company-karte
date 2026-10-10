<script setup lang="ts">
import type { SourceDetail } from '@/types/company';
import { computed } from 'vue';

const props = defineProps<{
    source: SourceDetail;
    analysisSourceLimit: number;
    reviewSplitLimit: number;
    /** 取り込み時に除去した定型行の件数（null = この機能より前の取り込みで未記録） */
    removedLines: number | null;
    /** 定型行の除去（SourceTextCleaner）を通した種別か。面接メモは通さない */
    cleanerApplied: boolean;
}>();

// 口コミは分割済みアイテムが分析材料（原本はここでは確認用）。
// それ以外は生テキストが先頭 analysisSourceLimit 字まで分析に渡る。
const isReview = computed(() => props.source.reviewItems !== null);
const isTruncated = computed(() => !isReview.value && props.source.truncatedText.length > 0);
const truncatedLength = computed(() => props.source.length - props.analysisSourceLimit);
// 見出し右の範囲表示用（字数は PHP が確定した source.length に合わせる）
const analyzedLength = computed(() => Math.min(props.source.length, props.analysisSourceLimit));
</script>

<template>
    <section class="mt-[30px]">
        <h2 class="mb-[10px] border-b-2 border-ink pb-[5px] font-serif text-[18px] font-semibold text-ink">
            取り込んだ本文
        </h2>

        <!-- 取り込み時に何を落としたかを隠さない（未記録は「未記録」と出す） -->
        <p class="mb-3 text-[12px] leading-5 text-ink-2">
            <template v-if="!cleanerApplied">面接メモは定型行の除去をしていません（本人の記録をそのまま保存）。</template>
            <template v-else-if="removedLines === null">除去した定型行の件数は未記録です（この機能より前に取り込んだソース）。</template>
            <template v-else-if="removedLines === 0">除去した定型行はありません。</template>
            <template v-else>
                除去した定型行 <span class="tabular-nums">{{ removedLines.toLocaleString() }}</span>件（ナビ・フッター・応募ボタン等。保存前に落としています）。
            </template>
        </p>

        <!-- 口コミ: 原本の確認用 -->
        <template v-if="isReview">
            <p class="border-l-[3px] border-rule-input bg-surface px-3 py-2 text-[12px] leading-5 text-ink-2">
                口コミは上の「1件ずつに分割」した結果が分析の材料になります。以下は取り込んだ原本テキストです。
            </p>
            <p v-if="source.length > reviewSplitLimit"
                class="mt-2 border-l-[3px] border-check bg-check-bg px-3 py-2 text-[12px] leading-5 text-check-fg">
                分割の対象は先頭 <span class="tabular-nums">{{ reviewSplitLimit.toLocaleString() }}</span>字までです（このソースは
                <span class="tabular-nums">{{ source.length.toLocaleString() }}</span>字）。
            </p>

            <div class="mt-[14px] border border-rule-input bg-surface">
                <div class="border-b border-ink px-4 py-[9px] text-[12px] font-semibold text-ink">
                    取り込んだ原本テキスト<span class="font-normal text-ink-2">（全
                        <span class="tabular-nums">{{ source.length.toLocaleString() }}</span>字）</span>
                </div>
                <pre
                    class="max-h-[26rem] overflow-auto px-5 py-[18px] font-sans text-[13px] leading-6 whitespace-pre-wrap text-ink">{{ source.analyzedText + source.truncatedText }}</pre>
            </div>
        </template>

        <!-- 口コミ以外: 分析にどこまで渡るか -->
        <template v-else>
            <div v-if="isTruncated"
                class="border-l-[3px] border-check bg-check-bg px-[14px] py-[11px] text-[13px] leading-[21px] text-check-fg">
                このソースは <span class="tabular-nums">{{ source.length.toLocaleString() }}</span>字あり、<b class="font-semibold">分析に渡るのは先頭
                    <span class="tabular-nums">{{ analysisSourceLimit.toLocaleString() }}</span>字まで</b>です
                （残り <span class="tabular-nums">{{ truncatedLength.toLocaleString() }}</span>字は分析されません）。
                給与・勤務地などが後半にある場合は、必要な部分だけを「貼り付け」で投入し直すと確実です。
            </div>
            <p v-else class="border-l-[3px] border-match bg-match-bg px-3 py-2 text-[12px] leading-5 text-match-fg">
                全文（<span class="tabular-nums">{{ source.length.toLocaleString() }}</span>字）が分析に渡ります。
            </p>

            <div class="mt-[14px] border border-rule-input bg-surface">
                <div class="flex items-baseline justify-between gap-3 border-b border-ink px-4 py-[9px]">
                    <span class="text-[12px] font-semibold text-ink">
                        分析に渡る範囲<span class="font-normal text-ink-2">（先頭
                            <span class="tabular-nums">{{ analysisSourceLimit.toLocaleString() }}</span>字まで）</span>
                    </span>
                    <span class="shrink-0 font-mono text-[11px] tabular-nums text-ink-3">
                        1 – {{ analyzedLength.toLocaleString() }}字
                    </span>
                </div>
                <pre
                    class="max-h-[26rem] overflow-auto px-5 py-[18px] font-sans text-[13px] leading-6 whitespace-pre-wrap text-ink">{{ source.analyzedText }}</pre>

                <!-- 分析に渡る／渡らないの境界。この画面で一番強い罫にする -->
                <template v-if="isTruncated">
                    <div
                        class="flex items-baseline justify-between gap-3 border-t-2 border-b border-t-check border-b-rule bg-check-bg px-4 py-2">
                        <span class="text-[12px] font-semibold text-check-fg">ここから先は分析に渡りません</span>
                        <span class="shrink-0 font-mono text-[11px] tabular-nums text-check">
                            {{ (analysisSourceLimit + 1).toLocaleString() }}字以降 ／ {{ truncatedLength.toLocaleString() }}字
                        </span>
                    </div>
                    <pre
                        class="max-h-[18rem] overflow-auto px-5 py-[18px] font-sans text-[13px] leading-6 whitespace-pre-wrap text-ink-4">{{ source.truncatedText }}</pre>
                </template>
            </div>
        </template>
    </section>
</template>
