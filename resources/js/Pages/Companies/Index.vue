<script setup lang="ts">
import CompanyFilterBar from '@/components/company/CompanyFilterBar.vue';
import CompanyListTable from '@/components/company/CompanyListTable.vue';
import CompanySelectionActions from '@/components/company/CompanySelectionActions.vue';
import OnboardingGuide from '@/components/company/OnboardingGuide.vue';
import StatusBar from '@/components/shell/StatusBar.vue';
import { recommendationRank, useCompanyFilter } from '@/composables/useCompanyFilter';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { CompanyRow } from '@/types/company';
import { Head, Link, useForm, useRemember } from '@inertiajs/vue3';
import { computed, type Ref } from 'vue';

const props = defineProps<{ companies: CompanyRow[]; hasProfile: boolean }>();

// confirm_duplicate は「既存企業と同名だと分かった上で別法人として登録する」の明示同意。
// 通常の追加では毎回 false に戻し、名前を直した後の再送で重複検知が黙って飛ばされないようにする
const form = useForm({ name: '', position: '', listed: false, confirm_duplicate: false });
const submit = (confirmDuplicate = false) => {
    form.confirm_duplicate = confirmDuplicate;
    form.post('/companies');
};

const showGuide = !props.hasProfile || props.companies.length === 0;

// 絞り込み・並び替えはフロント完結（computed）。CompanyListTable には適用後の配列を渡すだけ
const { query, status, sort, favoriteOnly, statusOptions, favoriteCount, rows } = useCompanyFilter(() => props.companies);

// チェックした企業への操作（比較・一括削除）は CompanySelectionActions に委譲。
// 絞り込みで非表示になった選択済み企業も selected に残す
// （検索条件を変えながら対象を集められるようにするため。解除は「選択解除」で行う）
// 企業ページから「戻る」で選択が消えないよう、履歴に載せる（キーは絞り込みの 'company-filter' と別）
const selected = useRemember<number[]>([], 'company-selected') as Ref<number[]>;

// 状態バーの数字はすべて既存の props（companies）と画面状態から導く。サーバへの追加取得はしない
const analyzed = computed(() => props.companies.filter((c) => c.recommendation !== null));
const unanalyzedCount = computed(() => props.companies.length - analyzed.value.length);

// 分析済みの内訳「◎3 ○4 △2 ―1」。記号はサーバー（Recommendation enum）の値をそのまま並べる
const recommendationBreakdown = computed(() => {
    const counts = new Map<string, { symbol: string; count: number }>();
    for (const c of analyzed.value) {
        const hit = counts.get(c.recommendation!);
        if (hit) hit.count++;
        else counts.set(c.recommendation!, { symbol: c.recommendationSymbol ?? '', count: 1 });
    }

    return [...counts]
        .sort(([a], [b]) => recommendationRank(a) - recommendationRank(b))
        .map(([, v]) => `${v.symbol}${v.count}`)
        .join(' ');
});

const statusItems = computed(() => [
    { label: '登録', value: props.companies.length, note: `社（★${favoriteCount.value}）` },
    { label: '分析済み', value: analyzed.value.length, note: recommendationBreakdown.value },
    {
        label: '未分析',
        value: unanalyzedCount.value,
        note: '分析実行でおすすめ度が付きます',
        tone: (unanalyzedCount.value > 0 ? 'check' : 'normal') as 'check' | 'normal',
    },
    { label: '表示中', value: rows.value.length, note: `絞り込み後（全${props.companies.length}社）` },
    { label: '選択中', value: selected.value.length, note: '絞り込みで隠れた分も含む' },
]);
</script>

<template>
    <Head title="企業一覧" />
    <AppLayout rail :nav-badges="{ '/companies': companies.length }">
        <template #status><StatusBar :items="statusItems" /></template>

        <template #rail>
            <p class="text-[10px] tracking-[.1em] text-rail-muted">この一覧の見方</p>
            <ul class="mt-[6px] space-y-[6px] text-[12px] leading-[17px] text-rail-fg">
                <li>行の左の色帯はおすすめ度です</li>
                <li>★を付けた企業は並びで常に最上位</li>
                <li>チェックした企業は比較・一括削除ができます</li>
                <li>絞り込みで隠れた選択も保持されます</li>
            </ul>
        </template>

        <h1 class="sr-only">企業一覧</h1>

        <OnboardingGuide v-if="showGuide" :has-profile="hasProfile" />

        <!-- 検索・絞り込み・並び替え（フロント完結。ステータスセグメントは実データ由来）。
             選択中も残す。ハンドオフは操作バーへの差し替えを提案していたが、それだと選択したまま
             検索条件を変えられず「絞り込みで隠れた選択も保持」の動線自体が消えるため採らない -->
        <CompanyFilterBar v-if="companies.length" v-model:query="query" v-model:status="status"
            v-model:sort="sort" v-model:favorite-only="favoriteOnly" :status-options="statusOptions"
            :favorite-count="favoriteCount" :shown-count="rows.length" :total-count="companies.length" />

        <CompanySelectionActions :selected="selected" @clear="selected = []" />

        <!-- 絞り込みで非表示の選択済み企業も選択状態は維持される（selected は id 配列で、表示行と独立） -->
        <CompanyListTable :companies="rows" :total="companies.length" v-model:selected="selected">
            <template #newRow>
                <h2 class="sr-only">企業を追加</h2>
                <form class="flex flex-wrap items-center gap-[10px]" @submit.prevent="submit()">
                    <span aria-hidden="true" class="font-mono text-[13px] text-ink-3">＋</span>
                    <label class="sr-only" for="new-company-name">企業名</label>
                    <input id="new-company-name" v-model="form.name" type="text" required
                        placeholder="企業名（株式会社◯◯）" class="w-60 px-2 py-1 text-[12px]" />
                    <label class="sr-only" for="new-company-position">ポジション</label>
                    <input id="new-company-position" v-model="form.position" type="text"
                        placeholder="ポジション（バックエンドエンジニア）" class="w-60 px-2 py-1 text-[12px]" />
                    <label class="flex items-center gap-[5px] text-[12px]">
                        <input v-model="form.listed" type="checkbox" class="rounded-[2px] border-rule-input" />
                        上場企業
                    </label>
                    <button type="submit" :disabled="form.processing"
                        class="rounded-[2px] bg-ink btn-sm font-medium text-paper disabled:opacity-50">
                        追加して開く
                    </button>
                    <span class="text-[11px] text-ink-3">Enter でも追加</span>
                </form>
                <p v-if="form.errors.name" class="mt-2 text-[12px] text-conflict">{{ form.errors.name }}</p>
                <!-- 名寄せキーが既存企業と一致した場合。登録は塞がず、別法人としての続行を選べる -->
                <div v-if="form.errors.duplicate"
                    class="mt-2 flex flex-wrap items-center gap-[10px] border-l-[3px] border-check bg-check-bg px-3 py-2 text-[12px] text-check-fg">
                    <span>{{ form.errors.duplicate }}</span>
                    <Link v-if="form.errors.duplicateId" :href="`/companies/${form.errors.duplicateId}`"
                        class="font-medium underline underline-offset-[3px] hover:text-conflict">
                        登録済みの企業ページを開く
                    </Link>
                    <button type="button" @click="submit(true)" :disabled="form.processing"
                        class="rounded-[2px] border border-check bg-white btn-sm font-medium text-check-fg disabled:opacity-50">
                        別法人として登録する
                    </button>
                </div>
            </template>
        </CompanyListTable>
    </AppLayout>
</template>
