<script setup lang="ts">
import KarteCoverage from '@/components/karte/KarteCoverage.vue';
import KarteQuoteCheck from '@/components/karte/KarteQuoteCheck.vue';
import KarteRhetoric from '@/components/karte/KarteRhetoric.vue';
import KarteReviews from '@/components/karte/KarteReviews.vue';
import KarteSummary from '@/components/karte/KarteSummary.vue';
import KarteVerdicts from '@/components/karte/KarteVerdicts.vue';
import { useCompanyTabNav } from '@/composables/useCompanyTab';
import { VERDICT_META } from '@/constants/verdict';
import type { Coverage, OpenIssues } from '@/types/issue';
import type { Karte, Verdict } from '@/types/karte';
import { computed } from 'vue';

const props = defineProps<{
    karte: Karte;
    companyId: number;
    /**
     * 面接で確認する論点（カルテとは別に保存され、再分析で消えない）。
     * 一覧と消し込みは面接準備タブが持つ。ここでは件数と導線にだけ使う
     */
    openIssues: OpenIssues;
    coverage: Coverage;
}>();

// 節ナビから面接準備タブへ渡す（論点・逆質問は別タブに移したので、行き先を画面に残す）
const { setTab } = useCompanyTabNav();

/** 面接準備タブに何が待っているか。件数は PHP が確定した値をそのまま並べるだけ */
const interviewSummary = computed(() => {
    const parts: string[] = [];
    if (props.openIssues.items.length) {
        parts.push(`未解消${props.openIssues.pendingCount}`);
    }
    parts.push(
        props.karte.p6 && !props.karte.skipped.p6 ? `逆質問${props.karte.p6.questions.length}件` : '逆質問（未生成）',
    );

    return `（${parts.join('・')}）`;
});

/**
 * 節ナビの判定件数。文言と色は constants/verdict.ts（VERDICT_META）が正本で、
 * 並びは PHP の BuildCompanyPageAction::VERDICT_ORDER と同じ（矛盾→判定割れ→整合→検証不能）。
 */
const NAV_VERDICTS: Verdict[] = ['contradict', 'split', 'match', 'unverifiable'];
const navCounts = computed(() =>
    NAV_VERDICTS
        // 判定割れは1人で裏取りしたランでは常に0。0件のときだけ列を出さない（従来どおり）
        .filter((v) => v !== 'split' || props.karte.verdictCounts.split > 0)
        .map((v) => ({ key: v, label: VERDICT_META[v].label, cls: VERDICT_META[v].cls, n: props.karte.verdictCounts[v] })),
);

// アンカー先が <details>（危険サイン等）の場合は開いてからスクロールする
// （閉じた details へのジャンプはブラウザが自動展開しないため）
const jump = (id: string) => {
    const el = document.getElementById(id);
    if (el instanceof HTMLDetailsElement) {
        el.open = true;
    }
};
</script>

<template>
    <!-- 節は 40px 間隔で積む。各節の枠（見出しの2px罫・id・scroll-mt）は karte/* の各コンポーネントが持つ -->
    <div class="space-y-10">
        <!-- 節ナビ兼サマリ: 判定件数を俯瞰し、面接準備まで1クリックで飛べる。上下の罫で紙の帯にする -->
        <nav
            class="sticky top-0 z-10 flex flex-wrap items-baseline gap-x-[18px] gap-y-1 border-t-2 border-t-ink border-b border-b-rule bg-paper py-[9px] text-[12px] print:hidden">
            <!-- 日時はモバイル幅では隠してナビを1行に保つ（折返すとアンカー先が隠れるため） -->
            <span v-if="karte.finishedAt" class="hidden font-mono tabular-nums text-ink-3 sm:inline">分析: {{
                karte.finishedAt }}</span>
            <!--
                判定が割れなかった場合、1人で見た結果と3人が一致した結果は見た目で区別できない。
                判断の重みに関わるので、日時と違って画面幅で隠さない
            -->
            <span class="text-ink-3">裏取り{{ karte.panelSize }}人</span>
            <!-- 不足があるときだけ材料へのリンクを出す（揃っていれば見せない） -->
            <a v-if="coverage.missingCount" href="#karte-coverage" class="text-check hover:text-conflict">材料 不足{{
                coverage.missingCount }}</a>
            <a href="#karte-summary" class="border-l-2 border-ink pl-[7px] text-ink hover:text-conflict">総合所見</a>
            <a href="#karte-verdicts" class="flex items-baseline gap-[7px] text-ink hover:text-conflict">
                主張検証
                <span v-for="c in navCounts" :key="c.key" class="px-[6px] text-[11px] tabular-nums"
                    :class="c.cls">{{ c.label }} {{ c.n }}</span>
            </a>
            <a href="#karte-rhetoric" class="text-ink hover:text-conflict"
                @click="jump('karte-rhetoric')">危険サイン</a>
            <a href="#karte-reviews" class="text-ink hover:text-conflict">口コミ</a>
            <!-- 論点と逆質問は面接準備タブ。どこまでスクロールしても件数と行き先が見えるようにする -->
            <button type="button" class="border-l-2 border-ink pl-[7px] font-semibold text-ink hover:text-conflict"
                @click="setTab('interview')">面接準備へ<span class="font-normal text-ink-3">{{ interviewSummary }}</span></button>
            <!-- Markdown エクスポート（Obsidian 等への持ち出し）。Inertia を通さず素のダウンロード -->
            <a :href="`/companies/${companyId}/export.md`"
                class="ml-auto text-ink-2 hover:text-conflict print:hidden">⬇ Markdown</a>
        </nav>

        <KarteCoverage :coverage="coverage" />
        <KarteSummary :p5="karte.p5" :recommendation="karte.recommendation"
            :recommendation-reason="karte.recommendationReason" :diff="karte.diff"
            :skipped-reason="karte.skipped.p5 ?? null" :check-split-note="karte.checkSplitNote" />
        <KarteQuoteCheck :p1="karte.p1" :claim-count="karte.claimCount" />
        <!-- 追跡した論点は面接準備タブに移した。追跡を始めるこの場所に、確認する場所への導線を残す -->
        <div>
            <KarteVerdicts :p2="karte.p2" :counts="karte.verdictCounts" :skipped-reason="karte.skipped.p2 ?? null"
                :company-id="companyId" :run-id="karte.runId"
                :has-machine="karte.hasMachineJudgment" />
            <!-- 主張検証の節が出ないラン（p2 が無くスキップ理由も無い）では導線だけが浮くので出さない -->
            <p v-if="karte.p2 || karte.skipped.p2" class="mt-[10px] text-[12px] leading-[20px] text-ink-3 print:hidden">
                「論点として追う」で追跡した主張は
                <button type="button" class="text-ink underline underline-offset-[3px] hover:text-conflict"
                    @click="setTab('interview', 'karte-issues')">面接準備タブの「面接で確認する論点」</button>
                に積まれます<template v-if="openIssues.items.length">（未解消 {{ openIssues.pendingCount }} / 全{{
                    openIssues.items.length }}件）</template>。面接での回答はそこで消し込みます。
            </p>
        </div>
        <KarteRhetoric :p3="karte.p3" :skipped-reason="karte.skipped.p3 ?? null" />
        <KarteReviews :p4="karte.p4" :skipped-reason="karte.skipped.p4 ?? null" />
    </div>
</template>
