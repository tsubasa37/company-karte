<script setup lang="ts">
// スキップされたステップの「理由がある空」を明示する（原因不明の空欄にしない）
defineProps<{ id: string; title: string; reason: string }>();
</script>

<template>
    <section :id="id" class="scroll-mt-24">
        <h2 class="border-b-2 border-ink pb-[5px] font-serif text-[19px] font-semibold text-ink">{{ title }}</h2>
        <p class="mt-[10px] border-l-[3px] border-rule-input bg-bench px-3 py-2 text-[12px] leading-[20px] text-ink-3">
            このステップは実行されませんでした — 理由: {{ reason }}
        </p>
    </section>
</template>
