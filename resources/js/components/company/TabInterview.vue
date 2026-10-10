<script setup lang="ts">
import BriefingView from '@/components/company/BriefingView.vue';
import KarteOpenIssues from '@/components/karte/KarteOpenIssues.vue';
import KarteQuestions from '@/components/karte/KarteQuestions.vue';
import { useCompanyTabNav } from '@/composables/useCompanyTab';
import type { Briefing, Option, RunMeta } from '@/types/company';
import type { OpenIssues } from '@/types/issue';
import type { Karte } from '@/types/karte';
import { computed } from 'vue';

const props = defineProps<{
    companyId: number;
    karte: Karte | null;
    briefing: Briefing;
    run: RunMeta | null;
    hasSources: boolean;
    /** 面接で確認する論点（カルテとは別に保存され、再分析で消えない） */
    openIssues: OpenIssues;
    issueStatusOptions: Option[];
    /** 面接メモの有無（論点の自動照合が走る条件） */
    hasInterviewNote: boolean;
}>();

// 評価タブ（主張検証）へ戻る導線。論点の出どころは別タブに残っている
const { setTab } = useCompanyTabNav();

// 逆質問は分析に含めないため未生成の状態がある。節ナビでも生成済みかを明示する
const questionsLabel = computed(() =>
    props.karte?.p6 && !props.karte.skipped.p6 ? ` ${props.karte.p6.questions.length}件` : '（未生成）',
);
</script>

<template>
    <!-- ルートは単一要素にする（親が v-show で切り替えるため。フラグメントには v-show が効かない） -->
    <div>
        <!-- 節は 40px 間隔で積む。節ナビの作りは評価タブ（KarteView）と揃える -->
        <div v-if="karte" class="space-y-10">
            <nav
                class="sticky top-0 z-10 flex flex-wrap items-baseline gap-x-[18px] gap-y-1 border-t-2 border-t-ink border-b border-b-rule bg-paper py-[9px] text-[12px] print:hidden">
                <a href="#karte-briefing" class="text-ink hover:text-conflict">ブリーフィング</a>
                <!-- どこまでスクロールしても未解消件数が見えるようにする（面接前に潰す対象） -->
                <a href="#karte-issues" class="text-ink hover:text-conflict">
                    論点<template v-if="openIssues.items.length"> 未解消{{ openIssues.pendingCount }}</template>
                </a>
                <a href="#karte-questions" class="font-semibold text-ink hover:text-conflict">逆質問{{
                    questionsLabel }}</a>
                <!-- 論点の出どころ（主張検証）は評価タブ。判定の根拠を見直す導線をここに残す -->
                <button type="button" class="border-l-2 border-ink pl-[7px] text-ink hover:text-conflict"
                    @click="setTab('evaluation', 'karte-verdicts')">評価へ（主張検証）</button>
                <!-- 面接を終えたら回答を残す先（ソースタブの面接記録フォーム） -->
                <button type="button" class="text-ink hover:text-conflict"
                    @click="setTab('sources', 'interview-record')">面接記録を追加</button>
                <!-- Markdown エクスポート（Obsidian 等への持ち出し）。Inertia を通さず素のダウンロード -->
                <a :href="`/companies/${companyId}/export.md`"
                    class="ml-auto text-ink-2 hover:text-conflict print:hidden">⬇ Markdown</a>
            </nav>

            <!-- 節の id は BriefingView の外側で持つ（節ナビからの行き先。BriefingView 自体は持たない） -->
            <div id="karte-briefing" class="scroll-mt-24">
                <BriefingView :company-id="companyId" :briefing="briefing" :stale-reason="briefing.staleReason" />
            </div>

            <div>
                <p class="mb-[10px] text-[12px] leading-[20px] text-ink-3 print:hidden">
                    ここに並ぶ論点は「評価」タブの
                    <button type="button" class="text-ink underline underline-offset-[3px] hover:text-conflict"
                        @click="setTab('evaluation', 'karte-verdicts')">主張検証</button>
                    で「論点として追う」を押した主張です。判定の根拠を見直すときは主張検証へ戻ってください。
                </p>
                <KarteOpenIssues :open-issues="openIssues" :status-options="issueStatusOptions"
                    :has-interview-note="hasInterviewNote" />
            </div>

            <KarteQuestions :p6="karte.p6" :skipped-reason="karte.skipped.p6 ?? null" :company-id="companyId"
                :state="karte.questions" />
        </div>
        <div v-else class="border border-rule bg-surface px-6 py-10 text-center">
            <p class="text-[13px] leading-[22px] text-ink-2">
                <template v-if="run?.isRunning">
                    分析を実行中です（数分かかります）。完了するとここに面接準備が表示されます。
                </template>
                <template v-else-if="hasSources">
                    まだ分析していません。右上の「分析実行」で主張検証を行います。逆質問は分析後にこのタブから生成できます。
                </template>
                <template v-else>
                    「ソース」タブから求人票や口コミを投入してください。投入後、右上の「分析実行」でカルテを生成します。
                </template>
            </p>
        </div>
    </div>
</template>
