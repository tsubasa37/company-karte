<script setup lang="ts">
import CompanyHeader from '@/components/company/CompanyHeader.vue';
import TabInfo from '@/components/company/TabInfo.vue';
import TabInterview from '@/components/company/TabInterview.vue';
import TabKarte from '@/components/company/TabKarte.vue';
import TabSources from '@/components/company/TabSources.vue';
import { useCompanyTab, type CompanyTab } from '@/composables/useCompanyTab';
import { useProgressPolling } from '@/composables/useProgressPolling';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { AnalyzeBlocked, Briefing, Company, EdinetStatus, Metric, Option, PanelSizeOption, RunMeta, Source } from '@/types/company';
import type { Benchmark, Coverage, OpenIssues } from '@/types/issue';
import type { Karte } from '@/types/karte';
import type { ScheduleEvent } from '@/types/schedule';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    company: Company;
    sources: Source[];
    officialMetrics: Metric[];
    run: RunMeta | null;
    karte: Karte | null;
    /** 面接で確認する論点（カルテとは別に保存され、再分析で消えない） */
    openIssues: OpenIssues;
    issueStatusOptions: Option[];
    /** 判定を固めるのにあと何の材料が要るか（AI は使わず投入済みの材料を数えた結果） */
    coverage: Coverage;
    /** 公式数値の、登録済み他社の中での位置（業界平均ではない） */
    benchmark: Benchmark[];
    briefing: Briefing;
    edinet: EdinetStatus;
    analyzeBlocked: AnalyzeBlocked | null;
    statusOptions: Option[];
    sourceTypeOptions: Option[];
    metricSourceOptions: Option[];
    panelSizeOptions: PanelSizeOption[];
    /** この企業の選考予定（時系列）。整形は PHP(ScheduleEventPresenter) 側で確定済み */
    scheduleEvents: ScheduleEvent[];
    scheduleKindOptions: Option[];
    /** URL の ?tab=（リロード・戻りでタブを失わないため。未指定は null。旧 karte は PHP が evaluation に寄せる） */
    activeTab: CompanyTab | null;
}>();

// タブの初期値: URL > 前回のタブ > 既定（未分析ならソースタブ = 先に材料を入れてもらう）。
// 分析済みなら常に評価タブ。選考ステータス等で開くタブを自動で変えない
const { tab, setTab } = useCompanyTab(props.company.id, props.activeTab ?? (props.karte ? 'evaluation' : 'sources'));

// 切り捨て判定はソースごとに PHP（limitFor）が確定済み。ここでは数えるだけ
const truncatedCount = computed(() => props.sources.filter((s) => s.isTruncated).length);

// 面接メモの有無。論点の自動照合が走る条件なので、カルテ側で明示するために渡す
const hasInterviewNote = computed(() => props.sources.some((s) => s.type === 'interview'));

// 進捗ポーリング（分析・ブリーフィング・逆質問生成・EDINET取得・口コミ分割の進行中のみ）。
// analyzeBlocked / sources を含めないと、完了後も「実行中です」やソースの分割待ち表示が残る
useProgressPolling(
    () =>
        (props.run?.isRunning ?? false) ||
        props.briefing.isGenerating ||
        props.edinet.isFetching ||
        // 逆質問の個別生成中（これが無いと生成完了しても画面が変わらない）
        (props.karte?.questions.isGenerating ?? false) ||
        props.sources.some((s) => s.isReview && s.splitAt === null),
    ['run', 'karte', 'company', 'briefing', 'edinet', 'officialMetrics', 'sources', 'analyzeBlocked',
        // 面接メモ投入に付随して走る論点の消し込みも、完了したら画面に出す
        'openIssues', 'coverage', 'benchmark'],
);

// 選択中は3pxの墨罫。丸ピル・色付き下線はやめ、太さと墨で現在地を示す
const tabCls = (name: string) =>
    tab.value === name ? 'border-ink font-semibold text-ink' : 'border-transparent text-ink-2 hover:text-ink';
</script>

<template>
    <Head :title="company.name" />
    <AppLayout rail>
        <!-- レールは全画面共通。ただしカルテは「読む」画面なので、本文の行長は max-w-5xl で保つ -->
        <div class="mx-auto max-w-5xl px-6 py-6">
        <Link href="/companies"
            class="text-[12px] text-ink-2 hover:text-conflict hover:underline hover:underline-offset-[3px] print:hidden">
            ← 企業一覧
        </Link>

        <CompanyHeader class="mt-[14px]" :company="company" :run="run" :has-karte="!!karte"
            :analyze-blocked="analyzeBlocked" :panel-size-options="panelSizeOptions" />

        <nav class="mt-6 mb-[18px] flex gap-[26px] border-b border-ink text-[14px] print:hidden" role="tablist">
            <button role="tab" :aria-selected="tab === 'evaluation'" class="-mb-px border-b-[3px] px-[2px] pb-2"
                :class="tabCls('evaluation')" @click="setTab('evaluation')">評価</button>
            <button role="tab" :aria-selected="tab === 'interview'" class="-mb-px border-b-[3px] px-[2px] pb-2"
                :class="tabCls('interview')" @click="setTab('interview')">
                面接準備
                <!-- 面接までに潰す件数はタブに出す（別タブに隠れても未解消が見えるように） -->
                <span v-if="openIssues.pendingCount" class="ml-[3px] font-mono text-[12px] tabular-nums text-check"
                    title="未解消の論点">{{ openIssues.pendingCount }}<span class="sr-only">件の未解消の論点</span></span>
            </button>
            <button role="tab" :aria-selected="tab === 'sources'" class="-mb-px border-b-[3px] px-[2px] pb-2"
                :class="tabCls('sources')" @click="setTab('sources')">
                ソース ({{ sources.length }})
                <!-- ⚠ の意味は記号だけでは読み上げに乗らないので、語も併記する -->
                <span v-if="truncatedCount" class="ml-1 text-check"
                    title="分析に渡らない部分があります">⚠<span class="sr-only">分析に渡らない部分があります</span></span>
            </button>
            <button role="tab" :aria-selected="tab === 'info'" class="-mb-px border-b-[3px] px-[2px] pb-2"
                :class="tabCls('info')" @click="setTab('info')">基本情報・メモ</button>
        </nav>

        <!-- v-show: タブを離れても入力を破棄しない（選考メモ・貼り付け欄の保護）。
             印刷は「評価 + 面接準備」の2タブを縦に連結して出す（print:block! で v-show の display:none を外す） -->
        <TabKarte v-show="tab === 'evaluation'" class="print:block!" :company-id="company.id" :karte="karte" :run="run"
            :has-sources="sources.length > 0" :open-issues="openIssues" :coverage="coverage" />
        <TabInterview v-show="tab === 'interview'" class="print:block!" :company-id="company.id" :karte="karte"
            :briefing="briefing" :run="run" :has-sources="sources.length > 0" :open-issues="openIssues"
            :issue-status-options="issueStatusOptions" :has-interview-note="hasInterviewNote" />
        <TabSources v-show="tab === 'sources'" class="print:hidden" :company-id="company.id" :sources="sources"
            :official-metrics="officialMetrics" :source-type-options="sourceTypeOptions"
            :metric-source-options="metricSourceOptions" :edinet="edinet" :benchmark="benchmark" />
        <TabInfo v-show="tab === 'info'" class="print:hidden" :company="company" :status-options="statusOptions"
            :schedule-events="scheduleEvents" :schedule-kind-options="scheduleKindOptions" />
        </div>
    </AppLayout>
</template>
