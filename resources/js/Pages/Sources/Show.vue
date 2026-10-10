<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import ReviewItemList from '@/components/company/ReviewItemList.vue';
import RevisionDiff from '@/components/company/RevisionDiff.vue';
import SourceEditPanel from '@/components/company/SourceEditPanel.vue';
import SourceRawText from '@/components/company/SourceRawText.vue';
import SourceRefetchButton from '@/components/company/SourceRefetchButton.vue';
import type { CompanyRef, Option, SourceDetail } from '@/types/company';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    source: SourceDetail;
    company: CompanyRef;
    analysisSourceLimit: number;
    reviewSplitLimit: number;
    /** 編集UI用（種別変更・企業付け替え・再取得・クリーニング結果。すべてPHPで確定した値） */
    sourceType: string;
    sourceTypeOptions: Option[];
    companyOptions: CompanyRef[];
    canRefetch: boolean;
    likelyClip: boolean;
    removedLines: number | null;
    cleanerApplied: boolean;
}>();

// 削除は2段階（誤クリック1回で口コミ分割結果まで消えるのを防ぐ）
const confirmingDelete = ref(false);
const deleteSource = () => router.delete(`/sources/${props.source.id}`);

// 編集パネルの開閉は見出し横の操作ボタンから（パネル本体は下に開く）
const editOpen = ref(false);

// 本文編集は面接メモだけ許可する（原文を持つソースは書き換えない）
const interviewBody = computed(() =>
    props.sourceType === 'interview' ? props.source.analyzedText + props.source.truncatedText : null,
);

// 全文を確認した流れのまま分析へ。開始後は企業ページに移り進捗を見せる
// （移動先は AnalysisController::store のリダイレクトが持つ）。
const analyzeForm = useForm({});
const analyze = () => analyzeForm.post(`/companies/${props.company.id}/analyze`);
</script>

<template>
    <Head :title="`${company.name} — ${source.typeLabel}`" />
    <AppLayout rail>
        <!-- レールは全画面共通。ただしソース詳細は「読む」画面なので、本文の行長は max-w-5xl で保つ -->
        <div class="mx-auto max-w-5xl px-6 py-6">
        <Link :href="`/companies/${company.id}?tab=sources`" class="text-[12px] text-ink-2 hover:text-conflict">
            ← {{ company.name }}（ソース一覧）
        </Link>

        <!--
          見出しと操作。再取得は SourceRefetchButton が自前で2段階を持つため、
          ボタンはこの行に並び、確認の帯は同コンポーネント側で `order-last w-full` により下段へ回る。
        -->
        <div class="mt-[14px] flex flex-wrap items-end justify-end gap-x-2 gap-y-3 border-b-2 border-ink pb-[14px]">
            <div class="mr-auto min-w-0 pr-2">
                <h1 class="flex flex-wrap items-baseline gap-x-[10px] font-serif text-[27px] font-semibold text-ink">
                    <span class="border-l-[3px] border-ink pl-[9px]">{{ source.typeLabel }}</span>
                    <span class="text-[18px] font-normal text-ink-2">投入したソース</span>
                </h1>
                <div class="mt-2 flex flex-wrap items-baseline text-[12px] text-ink-2">
                    <span class="pr-3 font-semibold tabular-nums">{{ source.length.toLocaleString() }}字</span>
                    <span class="border-l border-rule-input px-3">{{ source.originLabel }}</span>
                    <span v-if="source.fetchedAt" class="border-l border-rule-input px-3 font-mono tabular-nums">
                        取得: {{ source.fetchedAt }}
                    </span>
                    <span v-else-if="source.createdAt" class="border-l border-rule-input px-3 font-mono tabular-nums">
                        投入: {{ source.createdAt }}
                    </span>
                    <a v-if="source.url" :href="source.url" target="_blank" rel="noopener noreferrer"
                        class="border-l border-rule-input px-3 text-ink underline underline-offset-[3px] hover:text-conflict">
                        元ページを開く ↗
                    </a>
                </div>
            </div>

            <!-- URL のあるソースだけ取り直せる（書き換えに気づくため。旧本文は履歴に残る） -->
            <SourceRefetchButton v-if="canRefetch" :source-id="source.id" :likely-clip="likelyClip" />
            <button type="button" @click="editOpen = true"
                class="rounded-[2px] border border-rule-input bg-white btn-sm text-ink hover:bg-bench">
                種別・企業を変更{{ interviewBody !== null ? '／本文を編集' : '' }}
            </button>
            <button type="button" @click="analyze" :disabled="analyzeForm.processing"
                class="rounded-[2px] bg-ink btn-md font-medium text-paper disabled:opacity-50">
                この企業を分析
            </button>
            <button v-if="!confirmingDelete" type="button" @click="confirmingDelete = true"
                class="rounded-[2px] border border-conflict-border bg-white btn-sm text-conflict hover:bg-conflict-bg">
                削除
            </button>
        </div>

        <div v-if="confirmingDelete || analyzeForm.errors.analyze" class="mt-[14px] flex flex-col gap-[10px]">
            <!-- 削除の2段階目: 何が消えるかを見せてから実行する -->
            <div v-if="confirmingDelete"
                class="border-l-[3px] border-conflict bg-conflict-bg px-[14px] py-[11px] text-[13px] text-conflict-fg">
                <p class="mb-[9px] leading-[21px]">
                    この{{ source.typeLabel }}（{{ source.length.toLocaleString() }}字）を削除します。
                    <template v-if="source.reviewItems !== null">
                        分割済みの口コミ {{ source.reviewItems.length }}件も消え、この企業の他の口コミソースは再分割されます。
                    </template>
                    元に戻せません。
                </p>
                <div class="flex gap-2">
                    <button type="button" @click="deleteSource"
                        class="rounded-[2px] bg-conflict btn-sm font-medium text-white hover:bg-conflict-fg">
                        削除する
                    </button>
                    <button type="button" @click="confirmingDelete = false"
                        class="rounded-[2px] border border-rule-input bg-white btn-sm text-ink hover:bg-bench">
                        やめる
                    </button>
                </div>
            </div>

            <p v-if="analyzeForm.errors.analyze"
                class="border-l-[3px] border-conflict bg-conflict-bg px-3 py-2 text-[12px] text-conflict-fg">
                {{ analyzeForm.errors.analyze }}
            </p>
        </div>

        <SourceEditPanel v-model:open="editOpen" :source-id="source.id" :source-type="sourceType"
            :company-id="company.id" :source-type-options="sourceTypeOptions" :company-options="companyOptions"
            :interview-body="interviewBody" />

        <RevisionDiff v-if="source.revisions.length" :revisions="source.revisions" />
        <ReviewItemList v-if="source.reviewItems !== null" :items="source.reviewItems" :split-at="source.splitAt" />
        <SourceRawText :source="source" :analysis-source-limit="analysisSourceLimit" :review-split-limit="reviewSplitLimit"
            :removed-lines="removedLines" :cleaner-applied="cleanerApplied" />
        </div>
    </AppLayout>
</template>
