<script setup lang="ts">
/**
 * 設定の外枠。
 *
 * 以前は AppLayout の紙型（上部ヘッダー）＋設定だけの目次カラムという別のシェルだった。
 * その結果、同じメインナビが「他の画面では左レール」「設定では上部ヘッダー」と場所を変え、
 * 左に目次がもう1本増えていた。ナビの位置が画面で動くのは分かりにくいので、
 * 設定も作業台型（左レール）に載せ、5節はレールの「設定」の下に入れ子で開く。
 */
import { SETTINGS_SECTIONS, type SettingsNavValues } from '@/constants/settings';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { RailSections } from '@/types/shell';
import { computed } from 'vue';

// 節に添える現在値。1節=1ページなので、各ページが自分の props で分かるぶんだけ渡す
const props = defineProps<{ navValues?: SettingsNavValues }>();

// 暗い地の上なので紙地用の判定色は使わない（text-match は 1.99:1 で読めない）
const TONE_CLASS = {
    normal: 'text-rail-muted',
    match: 'text-rail-fg',
    check: 'text-rail-warn',
    conflict: 'text-rail-danger',
} as const;

// 値を持たない節は value を付けない（節名だけを出し、推測値や「—」を出さない）
const navSections = computed<RailSections>(() => ({
    '/settings': SETTINGS_SECTIONS.map((s) => {
        const v = props.navValues?.[s.href];

        return { href: s.href, label: s.label, value: v?.text ?? null, valueClass: TONE_CLASS[v?.tone ?? 'normal'] };
    }),
}));
</script>

<template>
    <AppLayout rail :nav-sections="navSections">
        <template #status><slot name="status" /></template>
        <div class="min-w-0 px-5 py-6"><slot /></div>
    </AppLayout>
</template>
