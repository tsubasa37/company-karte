<script setup lang="ts">
import type { SearchHit } from '@/types/search';
import { Link } from '@inertiajs/vue3';

defineProps<{ hits: SearchHit[] }>();

/**
 * ヒットから辿る先。ソース・口コミは本文が読めるソース詳細へ、
 * 論点・選考メモは企業ページの該当タブへ送る。
 */
const hrefFor = (hit: SearchHit): string => {
    if (hit.kind === 'source' || hit.kind === 'review') {
        return `/sources/${hit.id}`;
    }

    return hit.kind === 'issue'
        ? `/companies/${hit.companyId}?tab=interview#karte-issues`
        : `/companies/${hit.companyId}?tab=info`;
};
</script>

<template>
    <ul class="list-none p-0">
        <li v-for="(hit, i) in hits" :key="`${hit.kind}-${hit.id}-${i}`"
            class="border-b border-rule-thin py-[11px] last:border-0">
            <div class="flex flex-wrap items-baseline gap-[9px]">
                <Link :href="hrefFor(hit)" class="text-[13px] font-semibold text-ink hover:text-conflict">
                    {{ hit.kindLabel }}
                </Link>
                <span v-if="hit.title" class="font-mono text-[11px] text-ink-3">{{ hit.title }}</span>
            </div>
            <!-- 抜粋は PHP が切り出した3片をそのまま並べる（画面では本文を加工しない） -->
            <p v-for="(s, j) in hit.snippets" :key="j" class="mt-[4px] text-[12.5px] leading-[21px] text-ink-2">
                {{ s.before }}<mark
                    class="border-b-2 border-check bg-check-bg px-px font-medium text-ink">{{ s.match }}</mark>{{ s.after }}
            </p>
        </li>
    </ul>
</template>
