<script setup lang="ts">
// 必須/歓迎/NG条件のチェック結果リスト。記号・色の意味は meta で呼び出し側が確定する
defineProps<{
    title: string;
    items: { condition: string; status: string; evidence: string }[];
    meta: Record<string, { symbol: string; cls: string }>;
}>();
</script>

<template>
    <div>
        <div class="border-b border-ink pb-[4px] text-[12px] font-semibold text-ink">{{ title }}</div>
        <ul class="mt-2 flex list-none flex-col gap-[10px] p-0">
            <li v-for="(c, i) in items" :key="i" class="flex gap-2">
                <!-- 記号は明朝。◎○△― を色と合わせて意味を持たせる（絵文字は使わない）。
                     meta に無い status は無記号で流さず、判定が読めないことを出す -->
                <span v-if="meta[c.status]" class="shrink-0 font-serif text-[13px]" :class="meta[c.status].cls">
                    {{ meta[c.status].symbol }}
                </span>
                <span v-else class="shrink-0 text-[11px] whitespace-nowrap text-conflict" :title="c.status">不明</span>
                <span>
                    <span class="text-[13px] font-medium text-ink">{{ c.condition }}</span>
                    <span class="mt-[2px] block text-[11px] leading-[17px] text-ink-2">{{ c.evidence }}</span>
                </span>
            </li>
        </ul>
    </div>
</template>
