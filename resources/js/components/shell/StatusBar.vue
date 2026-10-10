<script setup lang="ts">
/**
 * 全画面共通の状態バー。ヘッダー／レールの直下（AppLayout の #status スロット）に置く。
 * 「その画面で最初に知りたい数字」を横一列で出す帯で、位置と高さは全画面で固定。
 *
 *   <AppLayout rail>
 *     <template #status>
 *       <StatusBar :items="[
 *         { label: '登録', value: 12, note: '社（★2）' },
 *         { label: '要対応', value: 2, note: '失敗1・滞留1', tone: 'conflict' },
 *         { label: 'ソース未投入', value: 3, note: '求人票なし', tone: 'check' },
 *       ]" />
 *     </template>
 *   </AppLayout>
 *
 * - 値の色は tone で決める。normal（既定・正常）/ check（要確認）/ conflict（異常）。
 *   「赤くしたいから conflict」ではなく、意味が異常なときだけ conflict にする
 * - 左右の余白（24px）と地色と下罫はこの帯が自分で持つ。#status スロット側は素通し
 * - 列は常に5等分。5件未満なら左詰めで空き列ができ、6件以上なら2段目に折り返す。
 *   どちらでも1列の幅は変わらないので、画面をまたいでも数字の位置が動かない
 */
import type { StatusItem, StatusTone } from '@/types/shell';

defineProps<{ items: StatusItem[] }>();

const VALUE_CLASS: Record<StatusTone, string> = {
    normal: 'text-ink',
    check: 'text-check',
    conflict: 'text-conflict',
};
</script>

<template>
    <dl class="grid grid-cols-5 gap-x-5 gap-y-3 border-b-2 border-ink bg-surface px-6 py-[13px]">
        <div v-for="(item, i) in items" :key="i" class="min-w-0">
            <dt class="text-[11px] tracking-[.06em] text-ink-3">{{ item.label }}</dt>
            <dd class="mt-px font-serif text-[21px] leading-[1.2] font-semibold tabular-nums"
                :class="VALUE_CLASS[item.tone ?? 'normal']">
                {{ item.value }}
            </dd>
            <dd v-if="item.note" class="mt-px text-[11px] leading-4 text-ink-2">{{ item.note }}</dd>
        </div>
    </dl>
</template>
