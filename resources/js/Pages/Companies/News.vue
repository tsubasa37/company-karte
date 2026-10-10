<script setup lang="ts">
import NewsArticleRow from '@/components/news/NewsArticleRow.vue';
import NewsScreenControl from '@/components/news/NewsScreenControl.vue';
import EmptyNote from '@/components/shell/EmptyNote.vue';
import StatusBar from '@/components/shell/StatusBar.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { CompanyRef } from '@/types/company';
import type { NewsArticle, NewsScreeningStatus, NewsScreenResult } from '@/types/news';
import type { StatusItem } from '@/types/shell';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    company: CompanyRef;
    /** 機械判定済みなら 関連あり（深刻度順）→ 未判定 → 関連なし の順（PHP が並べる） */
    articles: NewsArticle[];
    screening: NewsScreeningStatus;
    flash: { newsScreen: NewsScreenResult | null };
}>();

// 取り込みはユーザーが記事ごとに選ぶ（自動一括はしない）。
// 成功後は back() で articles が再取得され、サーバー照合の imported が立つ
const ingesting = ref<string | null>(null);
const error = ref<string | null>(null);

const ingest = (article: NewsArticle) => {
    ingesting.value = article.link;
    error.value = null;
    router.post(
        `/companies/${props.company.id}/sources`,
        { type: 'news', origin: 'url', url: article.link },
        {
            preserveScroll: true,
            // 記事側がbot拒否・404等で取れないことがあるので、画面内にエラーを出す
            onError: (errors) => (error.value = errors.url ?? '取り込みに失敗しました'),
            onFinish: () => (ingesting.value = null),
        },
    );
};

// 状態バー・レールの数字（表示用の集計のみ。取り込みの可否には関わらない）。
// 件数はいま出ている検索結果から数える（深刻の件数は PHP がしきい値で確定したもの）
const importedCount = computed(() => props.articles.filter((a) => a.imported).length);
const status = computed<StatusItem[]>(() => [
    { label: '検索結果', value: props.articles.length, note: '件（Bing News）' },
    { label: '取込済み', value: importedCount.value, note: '件' },
    { label: '未取込', value: props.articles.length - importedCount.value, note: '件' },
    { label: 'この企業のニュースソース', value: importedCount.value, note: '件（分析の材料）' },
    ...(props.screening.enabled ? [{ label: '深刻（機械判定）', value: props.screening.seriousCount, note: '件' }] : []),
]);
</script>

<template>
    <Head :title="`${company.name} — ニュース`" />
    <AppLayout rail>
        <template #status><StatusBar :items="status" /></template>

        <template #rail>
            <p class="text-[10px] tracking-[.1em] text-rail-muted">この企業のニュースソース</p>
            <p class="mt-[6px] font-serif text-[13px] leading-[19px] text-paper">{{ company.name }}</p>
            <p class="mt-[2px] font-mono text-[11px] tabular-nums text-rail-fg">
                取込済み {{ importedCount }} 件
            </p>

            <p class="mt-[14px] text-[10px] tracking-[.1em] text-rail-muted">巡回はしません</p>
            <p class="mt-[6px] text-[11px] leading-[18px] text-rail-fg">
                「取り込む」を押した記事だけ本文を取得して保存します。自動一括取り込みはありません。
            </p>
        </template>

        <section class="px-5 pt-[18px]">
            <Link :href="`/companies/${company.id}`"
                class="text-[12px] text-ink-2 hover:text-conflict hover:underline hover:underline-offset-[3px]">
                ← {{ company.name }}
            </Link>
            <h1 class="mt-3 font-serif text-[24px] leading-[1.3] font-semibold text-ink">
                ニュース検索: {{ company.name }}
            </h1>
            <p class="mt-2 max-w-[60rem] text-[12px] leading-5 text-ink-2">
                Bing News の検索結果です。カルテの材料にしたい記事だけ「取り込む」を押すと、本文を取得してニュースソースとして保存します。
            </p>
            <NewsScreenControl :company-id="company.id" :screening="screening" :result="flash.newsScreen" />

            <!-- 取り込み失敗。取得中（全ボタン無効）とは同時に起きない -->
            <p v-if="error"
                class="mt-[10px] border-l-[3px] border-conflict bg-conflict-bg px-3 py-2 text-[12px] leading-5 text-conflict-fg">
                {{ error }}
            </p>
        </section>

        <ul class="mt-4 border-t border-rule">
            <NewsArticleRow v-for="a in articles" :key="a.link" :article="a" :ingesting="ingesting" @ingest="ingest" />
            <EmptyNote v-if="articles.length === 0" as="li">
                この社名でのニュースは見つかりませんでした。
            </EmptyNote>
        </ul>
    </AppLayout>
</template>
