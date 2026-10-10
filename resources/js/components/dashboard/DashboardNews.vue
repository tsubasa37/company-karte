<script setup lang="ts">
import type { AlertBlock } from '@/types/company';
import type { NewsUncheckedItem, RecentNewsItem } from '@/types/dashboardAlerts';
import { Link } from '@inertiajs/vue3';

// 取り込み済みニュースだけを出す（ダッシュボードの描画では外部通信をしない）。
// 判定・文言・並び順は PHP（BuildDashboardAction）で確定済み
defineProps<{
    recentNews: AlertBlock<RecentNewsItem>;
    newsUnchecked: AlertBlock<NewsUncheckedItem>;
    /** 台帳の通し番号（確定は Dashboard.vue） */
    nos: { recentNews: number; newsUnchecked: number };
}>();
</script>

<template>
    <section v-if="recentNews.total"
        class="border-b border-b-rule-thin border-l-[3px] border-l-transparent py-[13px] pr-5 pl-[17px]"
        :class="nos.recentNews % 2 === 0 ? 'bg-surface-alt' : 'bg-surface'">
        <div class="grid grid-cols-[2.4rem_minmax(0,1fr)] gap-3">
            <div class="pt-px text-right">
                <div class="font-mono text-[11px] tabular-nums text-ink-4">{{ nos.recentNews }}</div>
                <div class="font-serif text-[15px] leading-[1.2] text-ink" aria-hidden="true">·</div>
            </div>
            <div class="min-w-0">
                <h2 class="font-serif text-[15px] font-semibold text-ink">
                    最近取り込んだニュース（{{ recentNews.total }}件）
                </h2>
                <ul class="mt-2 flex flex-col gap-1.5">
                    <li v-for="n in recentNews.items" :key="n.sourceId" class="flex flex-wrap items-baseline gap-2">
                        <span class="font-mono text-[11px] tabular-nums text-ink-3">{{ n.ingestedAt }}</span>
                        <Link :href="`/companies/${n.id}`"
                            class="text-[13px] font-medium text-ink hover:text-conflict hover:underline">
                            {{ n.name }}
                        </Link>
                        <Link :href="`/sources/${n.sourceId}`"
                            class="text-[12px] leading-[19px] text-ink-2 hover:text-conflict hover:underline">{{ n.title }}</Link>
                        <span v-if="n.host" class="font-mono text-[11px] text-ink-3">{{ n.host }}</span>
                    </li>
                    <li v-if="recentNews.more" class="text-[12px] text-ink-3">他{{ recentNews.more }}件</li>
                </ul>
            </div>
        </div>
    </section>

    <section v-if="newsUnchecked.total"
        class="border-b border-b-rule-thin border-l-[3px] border-l-check bg-surface py-[13px] pr-5 pl-[17px]">
        <div class="grid grid-cols-[2.4rem_minmax(0,1fr)] gap-3">
            <div class="pt-px text-right">
                <div class="font-mono text-[11px] tabular-nums text-ink-4">{{ nos.newsUnchecked }}</div>
                <div class="font-serif text-[15px] leading-[1.2] text-check" aria-hidden="true">○</div>
            </div>
            <div class="min-w-0">
                <h2 class="font-serif text-[15px] font-semibold text-check">
                    ニュースの確認漏れ（{{ newsUnchecked.total }}件・選考中）
                </h2>
                <p class="mt-[5px] text-[12px] leading-5 text-ink-2">
                    自動巡回はしません。ニュース一覧を開いて、不祥事・リストラなどの記事があれば取り込んでください。
                </p>
                <ul class="mt-2 flex flex-col gap-1.5">
                    <li v-for="c in newsUnchecked.items" :key="c.id" class="flex flex-wrap items-baseline gap-2">
                        <Link :href="`/companies/${c.id}/news`"
                            class="text-[13px] font-medium text-ink hover:text-conflict hover:underline">
                            {{ c.name }}
                        </Link>
                        <span class="text-[12px] leading-[19px] text-check">{{ c.statusLabel }} / {{ c.label }}</span>
                    </li>
                    <li v-if="newsUnchecked.more" class="text-[12px] text-ink-3">他{{ newsUnchecked.more }}件</li>
                </ul>
            </div>
        </div>
    </section>
</template>
