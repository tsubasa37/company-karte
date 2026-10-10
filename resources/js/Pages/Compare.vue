<script setup lang="ts">
import CompareTable from '@/components/company/CompareTable.vue';
import EmptyNote from '@/components/shell/EmptyNote.vue';
import StatusBar from '@/components/shell/StatusBar.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { CompareRow, Option } from '@/types/company';
import type { StatusItem } from '@/types/shell';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    rows: CompareRow[];
    /** 既定表示で畳んだ未分析社数（0なら全件表示済み） */
    hiddenCount: number;
    showAll: boolean;
    /** 一覧のチェックボックスで選ばれた社数（未選択は null） */
    selectedCount: number | null;
    /** 現年収（万円）。null は未設定 = 差額を出せない */
    currentSalary: number | null;
    /** 絞り込み中の選考ステータス（未指定は null = 全ステータス） */
    status: string | null;
    statusOptions: Option[];
}>();

/** 絞り込み条件を保ったままリンクを組む（指定しなかった条件は現在の値を引き継ぐ） */
const url = (params: { status?: string | null; all?: boolean }): string => {
    const q = new URLSearchParams();
    const status = params.status === undefined ? props.status : params.status;
    const all = params.all === undefined ? props.showAll : params.all;
    if (status) q.set('status', status);
    if (all) q.set('all', '1');

    return q.toString() ? `/compare?${q}` : '/compare';
};

const tabClass = (active: boolean): string =>
    active ? 'bg-ink font-semibold text-paper' : 'text-ink-2 hover:bg-bench';


/**
 * 状態バー。いま表に出ている行から数えるだけで、集計は増やさない。
 * 「必須不足」「条件が未抽出」は 0 のときまで色を付けない（異常なときだけ色を出す）。
 */
const statusItems = computed<StatusItem[]>(() => {
    const analyzed = props.rows.filter((r) => r.analyzed).length;
    const high = props.rows.filter((r) => r.recommendation === 'high').length;
    const unmet = props.rows.filter((r) => r.mustUnmet > 0).length;
    const offers = props.rows.filter((r) => r.offerSalary !== null).length;
    // 条件抽出の材料はあるのに conditions が無い＝抽出できていない（求人票未投入とは別）
    const unextracted = props.rows.filter((r) => r.conditions === null && r.hasConditionSource).length;
    // 差額は PHP が確定計算済み。現年収が未設定なら全社 null になるので「—」
    const deltas = props.rows.map((r) => r.offerDelta).filter((d): d is number => d !== null);
    const best = deltas.length ? Math.max(...deltas) : null;

    return [
        {
            label: '比較中',
            value: props.rows.length,
            note: analyzed === props.rows.length ? '社（すべて分析済み）' : `社（分析済み ${analyzed}）`,
        },
        { label: '◎', value: high, note: '前向き検討' },
        { label: '必須不足', value: unmet, note: '社', tone: unmet > 0 ? 'conflict' : 'normal' },
        {
            // 選考ステータスの「オファー」ではなく、オファー額を入力済みの社数。同じ語で別のものを指さない
            label: 'オファー額',
            value: offers,
            note: `社が入力済 / 最高 ${best === null ? '—' : `${best >= 0 ? '+' : ''}${best}万`}`,
        },
        {
            label: '条件が未抽出',
            value: unextracted,
            note: '社（求人票の再保存で解消）',
            tone: unextracted > 0 ? 'check' : 'normal',
        },
    ];
});
</script>

<template>
    <Head title="比較" />
    <AppLayout rail :nav-badges="{ '/compare': rows.length }">
        <template #status>
            <StatusBar :items="statusItems" />
        </template>
        <template #rail>
            <p class="text-[10px] tracking-[.1em] text-rail-muted">この画面の見方</p>
            <p class="mt-[6px] text-[11px] leading-[18px] text-rail-fg">
                空欄は「—」で一律にせず、記載なし／未抽出／求人票未投入を書き分けています。
            </p>
        </template>

        <div class="px-5 pt-[18px]">
            <h1 class="font-serif text-[24px] leading-tight font-semibold text-ink">企業を比較</h1>
            <p class="mt-2 max-w-[62rem] text-[12px] leading-5 text-ink-2">
                おすすめ度の高い順。必須/歓迎はプロフィール条件の充足数（分析済みの企業のみ）。年収・リモート・休日は求人票からのAI抽出（記載の抜き出しのみ）。
                条件が「未抽出」の企業は、<strong class="font-semibold text-ink">求人票を再保存すると条件を再抽出します</strong>。
            </p>
            <!-- 差額が出せない理由は黙らず明示する -->
            <p v-if="currentSalary === null"
                class="mt-2 border-l-[3px] border-check bg-check-bg px-[11px] py-[7px] text-[12px] text-check-fg">
                現在の年収が未設定のため、オファーとの差額は表示できません。
                <Link href="/settings" class="text-ink underline underline-offset-[3px] hover:text-conflict">
                    設定で現年収を入力する
                </Link>
            </p>
        </div>

        <!-- 見送り済みを並べたままにしないための絞り込み（選択比較中は選択が優先なので出さない） -->
        <div v-if="selectedCount === null"
            class="mt-[14px] flex flex-wrap items-center gap-3 border-y border-rule bg-bench px-5 py-2">
            <div class="flex items-stretch overflow-hidden rounded-[2px] border border-rule-input bg-white">
                <Link :href="url({ status: null })" class="px-[10px] py-1 text-[12px]" :class="tabClass(status === null)">
                    すべて
                </Link>
                <Link v-for="o in statusOptions" :key="o.value" :href="url({ status: o.value })"
                    class="border-l border-rule px-[10px] py-1 text-[12px]" :class="tabClass(status === o.value)">
                    {{ o.label }}
                </Link>
            </div>
            <Link v-if="hiddenCount > 0" :href="url({ all: true })"
                class="text-[12px] text-ink-2 underline underline-offset-[3px] hover:text-conflict">
                未分析の{{ hiddenCount }}社も表示する
            </Link>
            <Link v-else-if="showAll" :href="url({ all: false })"
                class="text-[12px] text-ink-2 underline underline-offset-[3px] hover:text-conflict">
                未分析を隠す（分析済みのみ表示）
            </Link>
            <span class="ml-auto font-mono text-[12px] text-ink-3 tabular-nums">
                {{ rows.length }} / {{ rows.length + hiddenCount }}
            </span>
        </div>
        <div v-else class="mt-[14px] flex flex-wrap items-center gap-3 border-y border-rule bg-surface px-5 py-2">
            <span class="text-[12px] text-ink-2">選択した{{ selectedCount }}社を比較中</span>
            <Link href="/compare" class="text-[12px] text-ink underline underline-offset-[3px] hover:text-conflict">
                選択を解除（分析済み全社を表示）
            </Link>
        </div>

        <CompareTable v-if="rows.length" :rows="rows" :current-salary="currentSalary" />
        <EmptyNote v-else>
            <p>比較できる分析済みの企業がありません。</p>
            <Link v-if="hiddenCount > 0" :href="url({ all: true })"
                class="mt-2 inline-block text-ink underline underline-offset-[3px] hover:text-conflict">
                未分析の{{ hiddenCount }}社を表示する
            </Link>
        </EmptyNote>
    </AppLayout>
</template>
