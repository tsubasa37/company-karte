<script setup lang="ts">
import type { RevisionView } from '@/types/company';

defineProps<{ revisions: RevisionView[] }>();

// 件数は打ち切り前の総数（PHP で確定済み）を表示する
const counts = (r: RevisionView) => `+${r.diff.addTotal} / -${r.diff.delTotal}`;
</script>

<template>
    <section class="mt-[30px]">
        <h2 class="mb-1 border-b-2 border-ink pb-[5px] font-serif text-[18px] font-semibold text-ink">文言の変化</h2>

        <p class="mt-[9px] border-l-[3px] border-check bg-check-bg px-3 py-2 text-[12px] leading-5 text-check-fg">
            このソースは再取り込みで文言が変わっています（{{ revisions.length }}回）。給与レンジや「急募」等の変化はシグナルです。
        </p>

        <details v-for="r in revisions" :key="r.id" class="border-b border-rule-thin">
            <summary class="cursor-pointer py-[10px] text-[13px] text-ink">
                {{ r.replacedAt }} の再取り込みでの変化（<span class="tabular-nums">{{ counts(r) }}</span>行）
            </summary>
            <div
                class="mb-[10px] max-h-80 overflow-auto border border-rule bg-surface px-3 py-[10px] font-mono text-[11px] leading-[19px]">
                <p v-if="r.diff.lines.length === 0" class="text-ink-4">（行単位の差分はありません）</p>
                <!-- 打ち切りを黙って行わない: 省略があることと省略行数を必ず明示する -->
                <p v-else-if="r.diff.truncated" class="mb-[6px] tabular-nums text-check">
                    表示は先頭{{ r.diff.lines.length }}行のみ（他{{ r.diff.omitted.toLocaleString() }}行省略）
                </p>
                <p v-for="(d, i) in r.diff.lines" :key="i" class="whitespace-pre-wrap"
                    :class="d.type === 'add' ? 'text-match' : 'text-conflict line-through'">
                    {{ d.type === 'add' ? '+' : '-' }} {{ d.line }}
                </p>
            </div>
        </details>
    </section>
</template>
