<script setup lang="ts">
import type { MachineReviewAggregate } from '@/types/karte';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * 主張検証テーブルの「口コミ側（機械判定）」セル。
 * 件数と文言は PHP（ClaimReviewAggregate）が確定済みで、ここは描画だけ。確率は出さない。
 */
const props = defineProps<{
    machine: MachineReviewAggregate | null | undefined;
}>();

/** チップを並べすぎるとセルが縦に伸びるので、先頭だけ出して残りは件数にする */
const CHIP_LIMIT = 8;

const shownItems = computed(() => props.machine?.contradictItems.slice(0, CHIP_LIMIT) ?? []);
const hiddenCount = computed(() => Math.max(0, (props.machine?.contradictItems.length ?? 0) - CHIP_LIMIT));
</script>

<template>
    <!-- claim_index の無い旧ラン・判定していない主張は集計が無い -->
    <span v-if="!machine" class="text-ink-3">—</span>
    <template v-else>
        <p class="text-[12px] leading-[20px] text-ink-2">{{ machine.summary }}</p>
        <div v-if="shownItems.length" class="mt-[6px]">
            <p class="text-[11px] text-ink-3">矛盾の口コミ</p>
            <div class="mt-[3px] flex flex-wrap gap-[5px]">
                <Link v-for="item in shownItems" :key="item.itemId" :href="`/sources/${item.sourceId}`"
                    class="rounded-[2px] border border-rule-input px-[5px] py-px font-mono text-[11px] text-ink-2 hover:text-conflict">
                    口コミ #{{ item.itemId }}
                </Link>
                <span v-if="hiddenCount" class="text-[11px] text-ink-3">+{{ hiddenCount }} 件</span>
            </div>
        </div>
        <!-- LLM の判定と逆向きの口コミが一定数あるときだけ。どちらが正しいかは決めず、確認を促す -->
        <span v-if="machine.flag"
            class="mt-[6px] inline-block border-l-2 border-check bg-check-bg px-2 py-[2px] text-[11px] font-medium whitespace-nowrap text-check">
            要確認（機械判定と食い違い）
        </span>
    </template>
</template>
