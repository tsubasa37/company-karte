<script setup lang="ts">
import SourceRefetchButton from '@/components/company/SourceRefetchButton.vue';
import type { Source } from '@/types/company';

defineProps<{ s: Source }>();
</script>

<template>
    <li class="border-b border-rule-thin last:border-b-0">
        <!-- 全文ページは別タブで開く。この一覧のすぐ下に面接記録・貼り付けの入力欄があり、
             同じタブで遷移すると書きかけの内容がそのまま失われるため -->
        <a :href="`/sources/${s.id}`" target="_blank" rel="noopener noreferrer"
            class="block px-4 py-3 hover:bg-bench">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-[6px]">
                <span
                    class="rounded-[2px] border border-rule-input bg-white px-[7px] py-[2px] text-[11px] text-ink">{{ s.typeLabel }}</span>
                <!-- どのサイトのいつの情報かを開かずに識別できるようにする -->
                <span v-if="s.host" class="font-mono text-[11px] text-ink-2">{{ s.host }}</span>
                <span v-else class="text-[11px] text-ink-3">貼り付け</span>
                <!-- クリップ時のページタイトル（無ければ非表示） -->
                <span v-if="s.title" class="max-w-64 truncate text-[11px] text-ink-3" :title="s.title">{{ s.title }}</span>
                <span class="font-mono text-[11px] tabular-nums text-ink-3">{{ s.fetchedAt ?? s.createdAt }}</span>
                <span class="text-[11px] text-ink-3">
                    <span class="font-mono tabular-nums">{{ s.length.toLocaleString() }}</span>字
                </span>
                <span v-if="s.isTruncated"
                    class="rounded-[2px] border-l-2 border-check bg-check-bg px-[7px] py-[2px] text-[11px] text-check-fg">
                    分析は先頭<span class="font-mono tabular-nums">{{ s.limit.toLocaleString() }}</span>字まで
                </span>
                <template v-if="s.isReview">
                    <span v-if="s.splitAt === null" class="border-l-2 border-ink pl-[6px] text-[11px] text-ink">
                        口コミ分割待ち
                    </span>
                    <span v-else class="text-[11px] text-ink-3">
                        口コミ <span class="font-mono tabular-nums">{{ s.reviewItemCount }}</span>件に分割済み
                    </span>
                </template>
                <span v-if="s.type === 'news'"
                    class="rounded-[2px] bg-match-bg px-[7px] py-[2px] text-[11px] text-match-fg"
                    title="分析時に「ニュース（公開報道）」として渡されます">分析に反映</span>
                <span v-if="s.revisionCount > 0"
                    class="rounded-[2px] bg-split-bg px-[7px] py-[2px] text-[11px] text-split-fg"
                    title="再取り込みで本文が変わった履歴があります（詳細ページで差分を表示）">
                    変更<span class="font-mono tabular-nums">{{ s.revisionCount }}</span>回
                </span>
            </div>
            <!-- AI の一行要約があればそれを優先し、無ければ本文冒頭の抜粋を出す -->
            <div v-if="s.summary" class="mt-[6px] truncate font-serif text-[13px] text-ink-2">{{ s.summary }}</div>
            <div v-else class="mt-[6px] truncate font-serif text-[13px] text-ink-2">{{ s.excerpt }}…</div>
        </a>
        <!-- 再取得は URL のあるソースだけ。リンクの入れ子にできないので行の下に置く -->
        <div v-if="s.url" class="space-y-2 px-4 pb-3">
            <SourceRefetchButton :source-id="s.id" />
        </div>
    </li>
</template>
