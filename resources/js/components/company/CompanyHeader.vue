<script setup lang="ts">
import AnalyzeControls from '@/components/company/AnalyzeControls.vue';
import LaborCheckNote from '@/components/company/LaborCheckNote.vue';
import { useCompanyTabNav } from '@/composables/useCompanyTab';
import { useRecommendation } from '@/composables/useRecommendation';
import type { AnalyzeBlocked, Company, PanelSizeOption, RunMeta } from '@/types/company';
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    company: Company;
    run: RunMeta | null;
    hasKarte: boolean;
    analyzeBlocked: AnalyzeBlocked | null;
    panelSizeOptions: PanelSizeOption[];
}>();

// 記号（◎○△―）とラベルはサーバー（Recommendation enum）が正。ここは色だけ決める
const { textClass, labelText } = useRecommendation();

// ソースタブへはページを再読込せず切り替える（v-show で保持している入力を捨てない）
const { setTab } = useCompanyTabNav();

// 再開・中断の失敗理由。サーバーは companies.show へ errors.analyze 付きで戻すので onError で拾って出す
const actionError = ref<string | null>(null);
const busy = ref(false);

// 分析実行・進捗はヘッダーに集約し、どのタブからでも操作/確認できるようにする
const postRunAction = (action: 'retry' | 'abort') => {
    if (!props.run) return;
    busy.value = true;
    actionError.value = null;
    router.post(`/companies/${props.company.id}/runs/${props.run.id}/${action}`, {}, {
        preserveScroll: true,
        onError: (errors) => (actionError.value = errors.analyze ?? '操作に失敗しました'),
        onFinish: () => (busy.value = false),
    });
};

// 進捗ステップは絵文字を使わず、色と太さ（実行中は墨の太字）で状態を区別する
const stepStatusCls = (s: string) =>
    ({
        done: 'text-match',
        running: 'font-semibold text-ink',
        failed: 'text-conflict',
        skipped: 'text-ink-3 line-through',
        pending: 'text-ink-4',
    })[s] ?? 'text-ink-4';
</script>

<template>
    <div>
        <!-- 企業見出し: 下罫2px で「紙の柱」を作る。右にカルテで最初に見るおすすめ度を置く -->
        <div class="flex flex-wrap items-end justify-between gap-5 border-b-2 border-ink pb-4">
            <div class="min-w-0">
                <h1 class="flex flex-wrap items-baseline gap-[10px] font-serif text-[31px] leading-[1.25] font-semibold text-ink">
                    {{ company.name }}
                    <span v-if="company.listed"
                        class="rounded-[2px] border border-ink-5 px-[6px] py-[2px] font-sans text-[11px] font-normal text-ink-2">上場</span>
                </h1>
                <p class="mt-[6px] text-[13px] text-ink-2">
                    <span v-if="company.position">{{ company.position }} ・ </span>
                    <span class="border-l-2 border-ink pl-[6px]">{{ company.statusLabel }}</span>
                </p>
            </div>
            <div class="flex flex-wrap items-end justify-end gap-x-7 gap-y-3">
                <div v-if="company.recommendation" class="text-right">
                    <div class="text-[11px] tracking-[.06em] text-ink-3">おすすめ度</div>
                    <div class="flex items-baseline justify-end gap-2" :class="textClass(company.recommendation)">
                        <span class="font-serif text-[34px] leading-[1.1]">{{ company.recommendationSymbol }}</span>
                        <span class="font-serif text-[18px] font-semibold">{{ labelText(company.recommendationLabel) }}</span>
                    </div>
                </div>
                <AnalyzeControls :company-id="company.id" :run="run" :has-karte="hasKarte"
                    :is-blocked="analyzeBlocked !== null" :panel-size-options="panelSizeOptions" />
            </div>
        </div>

        <!-- 押す前から「なぜ分析できないか」を出す（判定は PHP の AnalysisGuard） -->
        <p v-if="analyzeBlocked && !run?.isRunning"
            class="mt-3 border-l-[3px] border-check bg-check-bg px-[13px] py-[9px] text-[13px] leading-[21px] text-check-fg">
            {{ analyzeBlocked.message }}
            <Link v-if="analyzeBlocked.action === 'settings'" href="/settings/profile"
                class="underline underline-offset-[3px] hover:text-conflict">プロファイル設定を開く</Link>
            <Link v-else-if="analyzeBlocked.action === 'ai'" href="/settings/ai"
                class="underline underline-offset-[3px] hover:text-conflict">AIエンジン設定を開く</Link>
            <button v-else-if="analyzeBlocked.action === 'sources'" type="button"
                class="underline underline-offset-[3px] hover:text-conflict" @click="setTab('sources')">ソースタブへ</button>
        </p>

        <LaborCheckNote :labor-note="company.laborNote" :labor-checked-at="company.laborCheckedAt"
            :labor-note-history="company.laborNoteHistory" />

        <div v-if="run" class="mt-4 border-t border-rule pt-3">
            <!-- Queued 中は current_step が null なので「 を実行中」と空にならないよう出し分ける -->
            <div v-if="run.isRunning" role="status" class="mb-2 text-[13px] text-ink-2">
                <template v-if="run.currentStepLabel">{{ run.currentStepLabel }} を実行中…（数分かかります）</template>
                <template v-else>{{ run.statusLabel }}（queue:work が動いていないと進みません）</template>
            </div>
            <ol class="flex flex-wrap gap-y-1 text-[12px]">
                <li v-for="s in run.steps" :key="s.step"
                    class="mr-3 border-r border-rule py-[3px] pr-3 last:mr-0 last:border-r-0 last:pr-0"
                    :class="stepStatusCls(s.status)">
                    {{ s.label }}
                    <span v-if="s.status === 'done'">✓</span>
                    <span v-else-if="s.status === 'running'">…</span>
                    <span v-else-if="s.status === 'failed'">✗</span>
                    <span v-else-if="s.status === 'skipped'">－</span>
                </li>
            </ol>
            <!-- 応答が途絶えたラン。ワーカーの強制終了などで failed() が走らなかったときの復旧経路（判定は PHP） -->
            <div v-if="run.isRunning && run.isStuck" role="alert"
                class="mt-[10px] border-l-[3px] border-check bg-check-bg px-[13px] py-[9px] text-[13px] leading-[21px] text-check-fg">
                <div>
                    最後の更新（{{ run.lastActivityAt ?? '不明' }}）から{{ run.stuckAfterMinutes }}分以上応答がありません。queue:work が止まった可能性があります。
                    中断すると失敗扱いになり、失敗したステップから再開できます。
                </div>
                <button type="button" :disabled="busy" @click="postRunAction('abort')"
                    class="mt-2 rounded-[2px] border border-check bg-white btn-sm font-medium text-check-fg hover:bg-check-bg disabled:opacity-50">
                    中断して失敗扱いにする
                </button>
            </div>
            <div v-if="run.error" role="alert"
                class="mt-[10px] border-l-[3px] border-conflict bg-conflict-bg px-[13px] py-[9px] text-[13px] text-conflict-fg">
                <div>失敗: {{ run.error }}</div>
                <button type="button" :disabled="busy" @click="postRunAction('retry')"
                    class="mt-2 rounded-[2px] border border-conflict-border bg-white btn-sm font-medium text-conflict hover:bg-conflict-bg disabled:opacity-50">
                    失敗したステップから再開
                </button>
            </div>
            <p v-if="actionError" role="alert" class="mt-2 text-[12px] text-conflict">{{ actionError }}</p>
        </div>
    </div>
</template>
