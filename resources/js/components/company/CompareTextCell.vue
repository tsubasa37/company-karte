<script setup lang="ts">
/**
 * 求人票から抽出した文字列を出す比較表のセル。
 * 空のときは「—」で一律に済ませず、なぜ空なのか（missing）を代わりに出す。
 *
 * 既定は1行に畳む（wrap にすると列が潰れて行が縦に伸びる）。長い値は ClampText の
 * 「…全文」で開く。
 */
import ClampText from '@/components/shell/ClampText.vue';

defineProps<{ value: string | undefined; missing: string }>();
</script>

<template>
    <!-- 右罫（群の境界）は親が付けるので、下罫は色を辺指定にして取り合いにしない -->
    <td class="border-b border-b-rule-thin px-4 py-[10px] text-[12px]">
        <ClampText v-if="value" class="text-ink-2" :text="value" :lines="1" />
        <span v-else class="text-ink-4">{{ missing }}</span>
    </td>
</template>
