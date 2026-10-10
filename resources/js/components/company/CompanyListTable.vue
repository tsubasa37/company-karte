<script setup lang="ts">
import EmptyNote from '@/components/shell/EmptyNote.vue';
import { useRecommendation } from '@/composables/useRecommendation';
import type { CompanyRow } from '@/types/company';
import { Link, router } from '@inertiajs/vue3';

// total = 絞り込み前の全社数。0件表示が「未登録」なのか「絞り込みで0件」なのかを描き分ける
defineProps<{ companies: CompanyRow[]; total: number }>();

// 比較対象の選択（親が /compare?ids=… への遷移を担う）
const selected = defineModel<number[]>('selected', { default: () => [] });

// ラベルはサーバー（Recommendation enum）から来る。ここでは色だけ決める
const { textClass, labelText } = useRecommendation();

// 企業セルの左罫は「行全体の判定色」。border-current で textClass の色をそのまま罫線に使う
// （セル内の文字は各要素が自分の色を指定するので、currentColor は罫線にしか効かない）
const markClass = (c: CompanyRow) => (c.recommendation ? textClass(c.recommendation) : 'text-ink-4');

// 値指定で冪等（連打しても最後の状態に収束）。preserveState で検索・選択・並び設定を守る
const toggleFavorite = (c: CompanyRow) =>
    router.patch(`/companies/${c.id}/favorite`, { favorite: !c.favorite }, { preserveScroll: true, preserveState: true });
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr class="border-b border-rule-input bg-bench text-left">
                    <th scope="col" class="w-[30px] py-[6px] pl-5" title="チェックした企業を比較ビューで並べられます">
                        <span class="sr-only">選択</span>
                    </th>
                    <th scope="col" class="w-[22px] py-[6px] text-center text-[11px] font-medium text-ink-3"
                        title="お気に入り。付けた企業は一覧の先頭に固定されます（選考ステータスの「気になる」とは別です）">★</th>
                    <th scope="col" class="py-[6px] pl-2 text-[11px] font-medium text-ink-3">企業</th>
                    <th scope="col" class="w-[12rem] py-[6px] text-[11px] font-medium text-ink-3">ポジション</th>
                    <th scope="col" class="w-[7.5rem] py-[6px] text-[11px] font-medium text-ink-3"
                        title="企業ページの「基本情報・メモ」タブで変えられます。新しく登録した企業は「気になる」から始まります">選考ステータス</th>
                    <th scope="col" class="w-[13rem] py-[6px] text-[11px] font-medium text-ink-3">おすすめ度</th>
                    <th scope="col" class="w-[7rem] py-[6px] pr-5 text-right text-[11px] font-medium text-ink-3">更新</th>
                </tr>
            </thead>
            <tbody>
                <!-- 選択行の内枠（inset）は影ではなく枠。行を1pxも太らせずに選択を示す -->
                <tr v-for="(c, i) in companies" :key="c.id" class="h-[34px] border-b border-rule-thin"
                    :class="[
                        i % 2 === 1 ? 'bg-surface-alt' : 'bg-surface',
                        selected.includes(c.id) ? 'shadow-[inset_0_0_0_1px_#1B1A17]' : 'hover:bg-bench',
                    ]">
                    <td class="pl-5">
                        <!-- 行選択チェックボックスは表側に見出しラベルが無いので aria-label で欄名を持たせる -->
                        <input v-model="selected" type="checkbox" :value="c.id"
                            :aria-label="`${c.name} を比較対象に選択`" class="rounded-[2px] border-rule-input" />
                    </td>
                    <td class="text-center">
                        <button type="button" @click="toggleFavorite(c)" class="text-[13px] leading-none"
                            :title="c.favorite ? 'お気に入りを外す' : 'お気に入りにする（一覧の先頭に固定）'"
                            :class="c.favorite ? 'text-check' : 'text-ink-4 hover:text-check'"
                            :aria-label="c.favorite ? `${c.name} のお気に入りを外す` : `${c.name} をお気に入りにする`">
                            {{ c.favorite ? '★' : '☆' }}
                        </button>
                    </td>
                    <td class="border-l-[3px] border-current pl-2" :class="markClass(c)">
                        <Link :href="`/companies/${c.id}`" :title="c.name"
                            class="font-serif text-[14px] font-medium text-ink underline-offset-[3px] hover:text-conflict hover:underline">
                            {{ c.name }}
                        </Link>
                        <span v-if="c.listed"
                            class="ml-[6px] rounded-[2px] border border-rule-input px-1 text-[10px] text-ink-3">上場</span>
                    </td>
                    <td class="text-[12px] text-ink-2">
                        <span v-if="c.position">{{ c.position }}</span>
                        <span v-else class="text-ink-4">—</span>
                    </td>
                    <td class="text-[12px] text-ink">{{ c.statusLabel }}</td>
                    <td :class="textClass(c.recommendation)">
                        <template v-if="c.recommendation">
                            <span class="font-serif text-[18px] leading-none">{{ c.recommendationSymbol }}</span>
                            <span class="ml-[5px] text-[12px]">{{ labelText(c.recommendationLabel) }}</span>
                        </template>
                        <template v-else>
                            <span class="font-serif text-[18px] leading-none text-ink-4">―</span>
                            <span class="ml-[5px] text-[12px] text-ink-3">未分析</span>
                        </template>
                    </td>
                    <td class="pr-5 text-right font-mono text-[11px] tabular-nums text-ink-3">{{ c.updatedAt }}</td>
                </tr>
                <tr v-if="companies.length === 0" class="border-b border-rule-thin bg-surface">
                    <EmptyNote as="td" colspan="7">
                        {{ total === 0
                            ? 'まだ企業がありません。下の「＋」の行に企業名を入れて追加してください。'
                            : '条件に一致する企業がありません。検索・選考ステータス・★お気に入りだけ表示を見直してください。' }}
                    </EmptyNote>
                </tr>
                <!-- 追加フォームは表の末尾の「＋ 新規行」。中身は親（Pages/Companies/Index.vue）が入れる -->
                <tr v-if="$slots.newRow" class="border-b border-rule-input bg-bench">
                    <td colspan="7" class="px-5 py-[7px]"><slot name="newRow" /></td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
