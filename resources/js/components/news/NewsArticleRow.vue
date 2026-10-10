<script setup lang="ts">
import type { NewsArticle } from '@/types/news';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    article: NewsArticle;
    /** 取得中の記事の link（取得中は全行の「取り込む」を止める） */
    ingesting: string | null;
}>();

defineEmits<{ ingest: [article: NewsArticle] }>();

// 機械判定で関連なしの記事は行全体を薄くする（消さない。判定は外れうるため）
const dim = computed(() => props.article.screening?.relevant === false);
const severityChip = computed(() => {
    const s = props.article.screening;
    if (!s?.relevant || s.severity === 'neutral') return null;
    return {
        label: s.severityLabel,
        cls: s.severity === 'serious' ? 'border-conflict bg-conflict-bg text-conflict-fg' : 'border-check bg-check-bg text-check-fg',
    };
});
</script>

<template>
    <!-- 取込済みは左罫（整合色）＋地を紙より濃く、未取込は白地・罫なし。差は色ではなく濃度で出す -->
    <li class="flex items-center gap-[14px] border-b border-b-rule-thin border-l-[3px] py-[13px] pr-5 pl-[17px]"
        :class="article.imported ? 'border-l-match bg-surface-alt' : 'border-l-transparent bg-surface'">
        <div class="min-w-0 flex-1">
            <a :href="article.link" target="_blank" rel="noopener noreferrer"
                class="font-serif text-[14px] leading-[23px] font-semibold hover:text-conflict hover:underline hover:underline-offset-[3px]"
                :class="dim ? 'text-ink-4' : 'text-ink'">
                {{ article.title }}
            </a>
            <div class="mt-1 flex flex-wrap items-baseline text-[11px]" :class="dim ? 'text-ink-4' : 'text-ink-3'">
                <span class="font-mono">{{ article.source }}</span>
                <span v-if="article.publishedAt"
                    class="ml-[10px] border-l border-rule-input pl-[10px] font-mono tabular-nums">
                    {{ article.publishedAt }}
                </span>
                <template v-if="article.screening">
                    <span class="ml-[10px] border-l-2 border-rule-input bg-canvas px-[7px] py-px">
                        {{ article.screening.kindLabel }}
                    </span>
                    <span v-if="severityChip" class="ml-[6px] border-l-2 px-[7px] py-px" :class="severityChip.cls">
                        {{ severityChip.label }}
                    </span>
                    <span v-if="dim" class="ml-[10px] text-[10px]">関連度低（機械判定）</span>
                </template>
            </div>
        </div>
        <div class="flex shrink-0 items-center gap-[10px]">
            <template v-if="article.imported">
                <span class="border-l-2 border-match pl-[6px] text-[11px] whitespace-nowrap text-match-fg">
                    取込済み
                </span>
                <Link :href="`/sources/${article.sourceId}`"
                    class="text-[11px] whitespace-nowrap text-ink-2 underline underline-offset-[3px] hover:text-conflict">
                    ソースを開く
                </Link>
            </template>
            <!-- 取得中は全ての「取り込む」を無効にする（押した行だけ「取得中…」） -->
            <button v-else type="button" @click="$emit('ingest', article)" :disabled="ingesting !== null"
                class="rounded-[2px] border border-rule-input bg-white btn-sm whitespace-nowrap text-ink hover:bg-bench disabled:opacity-50">
                {{ ingesting === article.link ? '取得中…' : '取り込む' }}
            </button>
        </div>
    </li>
</template>
