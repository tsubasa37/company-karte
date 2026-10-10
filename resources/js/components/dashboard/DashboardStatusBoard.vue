<script setup lang="ts">
import { useRecommendation } from '@/composables/useRecommendation';
import type { StatusGroup } from '@/types/company';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

// groups は0件ステータスを含まない（0件は emptyGroups の1行に圧縮。確定はPHP側）
const props = defineProps<{ groups: StatusGroup[]; emptyGroups: string | null }>();

// 面接前ブリーフィングが意味を持つステータスだけ 🎤 マーカーを出す
const showsBriefing = (status: string) => status === 'interview' || status === 'offer';

// サマリは記号しか受け取らないので、色は記号から引く（未分析は薄墨の「·」。色の正は useRecommendation）
const { symbolClass } = useRecommendation();

// ★の件数。カードと同じデータから数えるので2か所の数が食い違わない（★の正は PHP の companies.favorite）
const favoriteCount = computed(() => props.groups.reduce((n, g) => n + g.companies.filter((c) => c.favorite).length, 0));

/** その選考ステータスで絞った企業一覧 */
const listUrl = (status: string) => `/companies?status=${status}`;
</script>

<template>
    <section class="border-t-2 border-t-ink px-5 pt-[18px] pb-6">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
            <h2 class="font-serif text-[17px] font-semibold text-ink">選考ステータス別</h2>
            <!-- ★の企業は数が増える前提なので名前は並べず、★だけに絞った一覧へ移る導線だけ出す -->
            <Link v-if="favoriteCount > 0" href="/companies?favorite=1"
                class="rounded-[2px] border border-rule-input bg-white btn-sm text-ink hover:bg-bench">
                <span class="text-check" aria-hidden="true">★</span>お気に入り
                <span class="font-mono tabular-nums">{{ favoriteCount }}</span>社を一覧で見る
            </Link>
        </div>
        <!-- 罫線はセルごとの outline で引く。地を覗かせる方式だと、最終行が埋まらないとき
                 空きカラムに地色が出る（白紙セルで埋めると今度は空の箱が並ぶ） -->
        <div class="grid grid-cols-1 gap-px sm:grid-cols-2 lg:grid-cols-3">
            <section v-for="g in groups" :key="g.status"
                class="bg-surface px-[13px] py-[11px] outline outline-1 outline-rule">
                <h3
                    class="mb-2 flex items-baseline justify-between gap-2 border-b border-b-ink pb-[5px] text-[12px] font-semibold text-ink">
                    {{ g.label }}
                    <Link v-if="g.active" :href="listUrl(g.status)"
                        class="font-mono text-[12px] font-medium tabular-nums text-ink underline underline-offset-[3px] hover:text-conflict"
                        :aria-label="`${g.label}の企業を一覧で見る（${g.companies.length}社）`">{{ g.companies.length }}</Link>
                </h3>
                <!-- 気になる・見送りは増える一方なので、名前を並べず件数と一覧への導線だけにする（判定は PHP の active） -->
                <div v-if="!g.active" class="flex items-baseline justify-between gap-3">
                    <span class="font-serif text-[20px] font-semibold tabular-nums text-ink">
                        {{ g.companies.length }}<span class="ml-[2px] font-sans text-[12px] font-normal text-ink-2">社</span>
                    </span>
                    <Link :href="listUrl(g.status)"
                        class="rounded-[2px] border border-rule-input bg-white btn-sm text-ink hover:bg-bench">一覧で見る</Link>
                </div>
                <ul v-else class="flex flex-col gap-[5px]">
                    <li v-for="c in g.companies" :key="c.id" class="flex items-baseline gap-[7px]">
                        <span class="w-[14px] shrink-0 text-center font-serif text-[13px]"
                            :class="symbolClass(c.recommendationSymbol)"
                            :title="c.recommendationSymbol ? 'おすすめ度' : ''">
                            {{ c.recommendationSymbol ?? '·' }}
                        </span>
                        <Link :href="`/companies/${c.id}`"
                            class="flex-1 truncate text-[13px] text-ink hover:text-conflict hover:underline">{{ c.name }}</Link>
                        <Link v-if="showsBriefing(g.status)" :href="`/companies/${c.id}?tab=interview`"
                            class="shrink-0 text-[11px]" :class="c.hasBriefing ? 'text-ink' : 'text-ink-4'"
                            :title="c.hasBriefing ? 'ブリーフィング生成済み' : 'ブリーフィング未生成'">
                            {{ c.hasBriefing ? '🎤' : '🎤未' }}
                        </Link>
                    </li>
                </ul>
            </section>
        </div>
        <p v-if="emptyGroups" class="mt-3 text-[11px] text-ink-3">{{ emptyGroups }}</p>
    </section>
</template>
