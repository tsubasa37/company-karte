<script setup lang="ts">
/**
 * 作業台型（AppLayout の rail モード）の左レール。ブランド・ナビ・件数バッジ・下段の常設情報まで
 * レール全体をここが持つ。**ページからは直接使わない。AppLayout 経由で使う。**
 *
 *   <AppLayout rail :nav-badges="{ '/companies': 12, '/': { text: '3', tone: 'danger' } }">
 *     <template #rail>
 *       <p class="text-[10px] tracking-[.1em] text-rail-muted">要対応</p>
 *       <p class="mt-[6px] text-[11px] leading-[18px] text-rail-danger">失敗ラン 1</p>
 *     </template>
 *   </AppLayout>
 *
 * - nav-badges のキーは AppLayout の NAV の href。値は文字列・数値、または { text, tone }。
 *   tone は danger（危険）/ warn（注意）/ normal（既定）。空文字と undefined はバッジを出さない
 * - sections は現在地のナビ行の下に入れ子で開く節（設定の5節など）。現在地でないときは出ない。
 *   これで「設定だけ上部ヘッダー＋別の目次」という別シェルを作らずに済む
 * - 既定スロット = レール下段。罫線で区切って画面ごとの常設情報（要対応の内訳・取り込み範囲・
 *   見方の注記など）を入れる。渡さなければ罫線ごと出ない
 * - 文字色は暗い地の上の指定（text-rail-fg / text-rail-muted / text-rail-danger / text-rail-warn）。
 *   紙地用の text-ink-* は暗い地では沈む（ink-3 で 3.27:1）ので使わない。スロットの中身も同じ
 */
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import type { Badge, BadgeTone, NavItem, RailSections } from '@/types/shell';

const props = defineProps<{
    nav: readonly NavItem[];
    current: string | null;
    badges?: Record<string, Badge>;
    /** 現在地のナビ行の下に開く節。キーは nav の href */
    sections?: RailSections;
    /** 節の現在地（節ページの URL） */
    sectionCurrent?: string | null;
}>();

// バッジの色。現在地の行だけ地が紙色に反転するので、暗い地用の色をそのまま置くと読めない。
// 同じ意味で明るい地用の色に差し替える。
// 通常色はデザイン正本が #8A8579 を指定しているが、暗い地（#1B1A17）の上の 11px では
// コントラスト 4.73:1 で沈んで読めないため、ナビ本体と同じ #C7C3B8（9.88:1）に上げる
const TONE_ON_RAIL: Record<BadgeTone, string> = {
    normal: 'text-rail-muted',
    danger: 'text-rail-danger',
    warn: 'text-rail-warn',
};
const TONE_ON_CURRENT: Record<BadgeTone, string> = {
    normal: 'text-ink-2',
    danger: 'text-conflict',
    warn: 'text-check',
};

// 使い方は NAV に含めない（ナビの一員ではなく常設の導線）ので、現在地は URL から直接見る
const page = usePage();
const onHelp = computed(() => page.url.split('?')[0].startsWith('/help'));

const items = computed(() =>
    props.nav.map((n) => {
        const raw = props.badges?.[n.href];
        const badge: { text: string; tone?: BadgeTone } | null =
            raw === undefined || raw === '' ? null : typeof raw === 'object' ? raw : { text: String(raw) };
        const tone = badge?.tone ?? 'normal';
        return {
            ...n,
            badge: badge?.text ?? null,
            badgeClass: props.current === n.href ? TONE_ON_CURRENT[tone] : TONE_ON_RAIL[tone],
        };
    }),
);
</script>

<template>
    <div class="sticky top-0 flex h-screen flex-col overflow-y-auto bg-rail pt-[18px] pb-6 text-rail-fg">
        <div class="border-b border-rail-rule px-4 pb-[18px]">
            <Link href="/" class="block font-serif text-[17px] font-semibold text-paper">企業カルテ</Link>
        </div>

        <nav aria-label="メイン" class="flex flex-col gap-px px-2 py-3">
            <template v-for="n in items" :key="n.href">
                <Link :href="n.href" :aria-current="current === n.href ? 'page' : undefined"
                    class="flex items-baseline justify-between gap-2 rounded-[2px] px-[10px] py-[7px] text-[13px]"
                    :class="current === n.href ? 'bg-paper font-semibold text-ink' : 'text-rail-muted hover:text-paper'">
                    <span>{{ n.label }}</span>
                    <span v-if="n.badge" class="font-mono text-[11px] tabular-nums" :class="n.badgeClass">
                        {{ n.badge }}
                    </span>
                </Link>

                <!-- 現在地の節（設定の5節など）。左罫でナビ本体と段差を付ける -->
                <div v-if="current === n.href && sections?.[n.href]?.length"
                    class="mt-px mb-[6px] ml-[10px] flex flex-col border-l border-rail-rule pl-[10px]">
                    <Link v-for="sec in sections[n.href]" :key="sec.href" :href="sec.href"
                        :aria-current="sectionCurrent === sec.href ? 'page' : undefined"
                        class="rounded-[2px] px-[8px] py-[5px] text-[12px]"
                        :class="sectionCurrent === sec.href
                            ? 'bg-rail-rule font-semibold text-paper'
                            : 'text-rail-muted hover:text-paper'">
                        <span class="block whitespace-nowrap">{{ sec.label }}</span>
                        <span v-if="sec.value" class="mt-px block text-[11px] whitespace-nowrap"
                            :class="sec.valueClass">{{ sec.value }}</span>
                    </Link>
                </div>
            </template>
        </nav>

        <div v-if="$slots.default" class="mt-[14px] border-t border-rail-rule px-4 pt-[14px]">
            <slot />
        </div>

        <!-- 使い方はナビの一員ではなく、どの画面からも同じ場所で開ける常設の導線。
             罫線でナビと切り離して最下段に置く -->
        <div class="mt-auto border-t border-rail-rule px-2 pt-[10px]">
            <Link href="/help" :aria-current="onHelp ? 'page' : undefined"
                class="block rounded-[2px] px-[10px] py-[7px] text-[12px]"
                :class="onHelp ? 'bg-paper font-semibold text-ink' : 'text-rail-muted hover:text-paper'">
                使い方
            </Link>
        </div>
    </div>
</template>
