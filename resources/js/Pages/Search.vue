<script setup lang="ts">
import HelpTip from '@/components/HelpTip.vue';
import SearchHitList from '@/components/SearchHitList.vue';
import EmptyNote from '@/components/shell/EmptyNote.vue';
import { HELP } from '@/constants/help';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { SearchGroup } from '@/types/search';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

// 型は types/search.ts が正。インポートした型をそのまま defineProps に渡すと
// SFC コンパイラが外部ファイルを解決できずビルドに失敗するため、フィールドは展開して書く
const props = defineProps<{
    query: string;
    /** 件数の多い企業から並ぶ（並び順の確定は PHP） */
    groups: SearchGroup[];
    total: number;
    /** 検索語が短すぎて実行しなかった（未入力と区別する） */
    tooShort: boolean;
}>();

const q = ref(props.query);

// 検索は GET。履歴を積むと戻るたびに検索結果を辿ることになるので置き換える
const submit = () => router.get('/search', { q: q.value }, { preserveState: true, replace: true });
</script>

<template>
    <Head title="検索" />
    <AppLayout rail>
        <div class="mx-auto max-w-[900px] px-6 py-7">
            <h1 class="border-b-2 border-ink pb-[5px] font-serif text-[22px] font-semibold text-ink">
                検索
                <HelpTip :help="HELP.search" />
            </h1>
            <p class="mt-2 text-[12px] leading-[20px] text-ink-2">
                取り込んだ求人票・口コミ・追跡中の論点・選考メモを、企業をまたいで検索します。
            </p>

            <form class="mt-4 flex gap-2" @submit.prevent="submit">
                <input v-model="q" type="search" aria-label="検索語" placeholder="例: 残業 / みなし / 裁量労働"
                    maxlength="200"
                    class="flex-1 border border-rule-input bg-white px-3 py-[7px] font-mono text-[13px] text-ink" />
                <button type="submit" class="rounded-[2px] bg-ink btn-md font-medium text-paper">
                    検索
                </button>
            </form>

            <p v-if="tooShort" class="mt-4 text-[12.5px] leading-[21px] text-check-fg">
                検索語が短すぎます（2文字以上）。1文字では材料のほとんどが該当してしまうため実行していません。
            </p>
            <p v-else-if="!query" class="mt-4 text-[12.5px] leading-[21px] text-ink-3">
                語を入れて検索してください。本文の部分一致で探します（大文字小文字は区別しません）。
            </p>
            <template v-else>
                <p class="mt-4 border-b border-rule pb-[6px] font-mono text-[12px] text-ink-2">
                    「{{ query }}」— {{ groups.length }}社 {{ total }}件
                </p>
                <EmptyNote v-if="!groups.length">
                    該当する記述はありませんでした。別の言い回しで探すか、企業ページからソースを投入してください。
                </EmptyNote>

                <!-- 件数の多い企業から並べる（並び順の確定は PHP 側） -->
                <section v-for="g in groups" :key="g.companyId" class="mt-7">
                    <h2 class="border-t-2 border-t-ink pt-[7px] font-serif text-[16px] font-bold">
                        <Link :href="`/companies/${g.companyId}`" class="text-ink hover:text-conflict">
                            {{ g.companyName }}
                        </Link>
                        <span class="ml-[9px] font-sans text-[11px] font-normal text-ink-3">{{ g.count }}件</span>
                    </h2>
                    <SearchHitList :hits="g.hits" />
                </section>
            </template>
        </div>
    </AppLayout>
</template>
