<script setup lang="ts">
/**
 * 空状態の1行メモ（一覧・表に出すものが無いときに置く）。
 *
 * 余白と書体をここ1本に集約する。作業台型の全幅レイアウトでは中央寄せが浮くため左寄せで置く。
 * <td> や <li> の中に置く箇所があるので、ルート要素のタグは as で選ぶ（既定は div）。
 * colspan などの属性は素通しで root へ乗る。
 */
withDefaults(defineProps<{ as?: string }>(), { as: 'div' });
</script>

<template>
    <component :is="as" class="px-5 py-8 text-[12px] leading-5 text-ink-3">
        <slot />
    </component>
</template>
