<script setup lang="ts">
/**
 * 全画面の外枠。デザインの型が2つあるので、ページ側が `rail` で選ぶ。
 *
 *   紙型（既定）  … カルテ / ソース詳細。上に横並びヘッダー、本文は中央寄せ（max-w-5xl）
 *   作業台型（rail）… 一覧 / 比較 / メール履歴 / ダッシュボード / 予定表 / ニュース検索 / 設定。
 *                     左に固定レール、本文は残り全幅。設定は節をレールに入れ子で出す（navSections）
 *
 * ページからの使い方:
 *
 *   紙型
 *     <AppLayout>            … 既定（本文 max-w-5xl）
 *
 *   作業台型
 *     <AppLayout rail :nav-badges="{ '/companies': 12, '/': { text: '3', tone: 'danger' } }">
 *       <template #status><StatusBar :items="..." /></template>
 *       <template #rail>…レール下段の常設情報…</template>
 *       …本文…
 *     </AppLayout>
 *
 * スロット
 *   #status  ヘッダー／レール直下の帯。左右余白を持たない（StatusBar が自前で持つ）
 *   #rail    左レール下段の常設情報。rail のときだけ描画される
 *   既定     本文。**作業台型では左右パディングが付かない**。ツールバーや表を全幅の帯で
 *            重ねる設計のため、各セクションが自分で px-5（20px）を持つこと。
 *            紙型は従来どおり本レイアウトが px-6 py-6 を持つ。
 *
 * nav-badges はレールのナビ右端に出す件数。キーは NAV の href。
 * 値は文字列・数値、または { text, tone: 'normal' | 'danger' | 'warn' }。
 */
import SideRail from '@/components/shell/SideRail.vue';
import type { NavBadges, RailSections } from '@/types/shell';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// 現在地の判定（前方一致。"/" は完全一致のみ、他は配下ページも含む）
const NAV = [
    { href: '/', label: 'ダッシュボード' },
    { href: '/companies', label: '企業一覧' },
    { href: '/schedule', label: '予定表' },
    { href: '/mails', label: 'メール' },
    { href: '/compare', label: '比較' },
    { href: '/search', label: '検索' },
    { href: '/settings', label: '設定' },
] as const;

// SideRail.vue の Badge と同じ形（型の実体はあちら。ここは受け渡しだけなので構造で合わせる）
// 型は types/shell.ts が正（SideRail と共有する）

const props = withDefaults(
    defineProps<{
        rail?: boolean;
        navBadges?: NavBadges;
        /** 現在地のナビ行の下にレールで開く節（設定の5節など） */
        navSections?: RailSections;
    }>(),
    { rail: false },
);

const page = usePage();
// 節（レールの入れ子）の現在地。節は1節=1ページなので URL の完全一致で決まる
const sectionCurrent = computed(() => page.url.split('?')[0]);
const current = computed(() => {
    const url = page.url.split('?')[0];
    return NAV.reduce<string | null>((hit, n) => {
        if (n.href === '/' ? url === '/' : url.startsWith(n.href)) return n.href;
        return hit;
    }, null);
});
</script>

<template>
    <!-- 作業台型 — 左レール + 本文。印刷時はレールを落として1段組にする -->
    <div v-if="rail" class="grid min-h-screen grid-cols-[12.5rem_minmax(0,1fr)] print:block">
        <SideRail :nav="NAV" :current="current" :badges="navBadges" :sections="navSections"
            :section-current="sectionCurrent" class="print:hidden">
            <slot name="rail" />
        </SideRail>
        <div class="min-w-0">
            <slot name="status" />
            <!-- 末尾の余白はここで持つ。ページ側で pb を書くと画面ごとにばらつく
                 （実測で pb-10 が2画面だけ、他4画面は0だった） -->
            <main class="min-w-0 pb-10"><slot /></main>
        </div>
    </div>

    <!-- 紙型（既定） -->
    <div v-else class="min-h-screen">
        <header class="border-b border-ink bg-surface print:hidden">
            <div class="mx-auto flex h-[58px] max-w-5xl items-center justify-between px-6">
                <Link href="/" class="font-serif text-[20px] font-semibold text-ink">企業カルテ</Link>
                <nav aria-label="メイン" class="flex items-baseline gap-[22px] text-[13px] text-ink-2">
                    <Link v-for="n in NAV" :key="n.href" :href="n.href"
                        :aria-current="current === n.href ? 'page' : undefined"
                        :class="current === n.href
                            ? 'border-b-2 border-ink pb-[2px] font-semibold text-ink'
                            : 'hover:text-ink'">
                        {{ n.label }}
                    </Link>
                </nav>
            </div>
        </header>
        <div class="mx-auto max-w-5xl"><slot name="status" /></div>
        <main class="mx-auto max-w-5xl px-6 py-6">
            <slot />
        </main>
    </div>
</template>
