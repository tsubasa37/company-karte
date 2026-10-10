<script setup lang="ts">
import type { HelpText } from '@/constants/help';
import { onBeforeUnmount, onMounted, ref } from 'vue';

defineProps<{ help: HelpText }>();

// ホバーでも出すが、タッチ環境ではホバーが無いのでクリックで固定できるようにする。
// hovered と pinned を分けているのは、クリックで開いた後にマウスが外れても閉じないようにするため
const hovered = ref(false);
const pinned = ref(false);
const root = ref<HTMLElement | null>(null);

const close = () => {
    pinned.value = false;
    hovered.value = false;
};

// 外側クリックと Esc で閉じる（開きっぱなしで他の操作を邪魔しない）
const onDocClick = (e: MouseEvent) => {
    if (pinned.value && root.value && !root.value.contains(e.target as Node)) close();
};
const onKeydown = (e: KeyboardEvent) => {
    if (e.key === 'Escape') close();
};

onMounted(() => {
    document.addEventListener('click', onDocClick);
    document.addEventListener('keydown', onKeydown);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onDocClick);
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <span ref="root" class="relative inline-block print:hidden" @mouseenter="hovered = true"
        @mouseleave="hovered = false">
        <!-- details の summary 内に置いても折りたたみを開閉させない（stop/prevent が必須） -->
        <button type="button" @click.stop.prevent="pinned = !pinned" :aria-expanded="pinned || hovered"
            :aria-label="`${help.title}の説明`"
            class="inline-flex h-4 w-4 items-center justify-center rounded-full border border-rule-input align-middle text-[10px] leading-none text-ink-3 hover:border-ink-3 hover:text-ink focus:outline-2 focus:-outline-offset-1 focus:outline-ink">
            i
        </button>
        <!-- details の summary 内でツールチップ本文をクリック（コピー等）しても折りたたみを開閉させない -->
        <span v-if="pinned || hovered" role="tooltip" @click.stop.prevent
            class="absolute top-6 left-0 z-30 w-80 max-w-[calc(100vw-2rem)] rounded-[2px] border border-rule bg-surface p-3 text-left text-xs leading-relaxed font-normal whitespace-normal text-ink-2">
            <span class="mb-1 block font-semibold text-ink">{{ help.title }}</span>
            <span v-for="(p, i) in help.body" :key="i" class="mb-1 block last:mb-0">{{ p }}</span>
        </span>
    </span>
</template>
