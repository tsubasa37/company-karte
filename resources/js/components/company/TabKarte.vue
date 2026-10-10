<script setup lang="ts">
import KarteView from '@/components/KarteView.vue';
import { useCompanyTabNav } from '@/composables/useCompanyTab';
import type { RunMeta } from '@/types/company';
import type { Coverage, OpenIssues } from '@/types/issue';
import type { Karte } from '@/types/karte';
import { computed } from 'vue';

const props = defineProps<{
    companyId: number;
    karte: Karte | null;
    run: RunMeta | null;
    hasSources: boolean;
    /** 件数だけ使う（一覧と消し込みは面接準備タブ）。評価タブから面接準備への導線に出す */
    openIssues: OpenIssues;
    coverage: Coverage;
}>();

// 同じページ内のタブ移動。Link だとサーバーへ取り直しに行き、入力中の欄も作り直される
const { setTab } = useCompanyTabNav();

// カルテは「最後に完了したラン」。最新ランが別（実行中・失敗）のときはその旨を明示する
const showingOldRun = computed(
    () => props.karte !== null && props.run !== null && props.run.id !== props.karte.runId,
);

// 分析後に変わった材料（件数判定は PHP）。実行中は次のカルテで反映されるので出さない
const stale = computed(() =>
    props.karte?.freshness.isStale && !props.run?.isRunning ? props.karte.freshness : null,
);

// 内訳の文言だけを組み立てる（件数は PHP が確定済み）
const parts = (source: number, metric: number) =>
    [source ? `ソース${source}件` : null, metric ? `公式数値${metric}件` : null]
        .filter((v): v is string => v !== null)
        .join('・');
const changedText = computed(() => (stale.value ? parts(stale.value.newSources, stale.value.newMetrics) : ''));
const removedText = computed(() =>
    stale.value ? parts(stale.value.removedSources, stale.value.removedMetrics) : '',
);
</script>

<template>
    <!-- ルートは単一要素にする（親が v-show で切り替えるため。フラグメントには v-show が効かない） -->
    <div>
    <template v-if="karte">
        <!-- どのランを見せているかを常に明示（黙って古い結果を出さない）。実行中は墨の帯 -->
        <p v-if="showingOldRun && run?.isRunning"
            class="mb-2 border-l-[3px] border-ink bg-surface px-[13px] py-[9px] text-[13px] leading-[21px] text-ink">
            表示中は前回の分析結果です（{{ karte.finishedAt }}）。新しい分析を実行中<template
                v-if="run?.currentStepLabel">（{{ run.currentStepLabel }}）</template>…完了すると自動で置き換わります。
        </p>
        <p v-else-if="showingOldRun && run?.error"
            class="mb-2 border-l-[3px] border-conflict bg-conflict-bg px-[13px] py-[9px] text-[13px] leading-[21px] text-conflict-fg">
            表示中は前回の分析結果です（{{ karte.finishedAt }}）。再分析は失敗しました — 上の「失敗したステップから再開」で続きから実行できます。
        </p>
        <!-- 分析が古い（材料が変わった）ことをカルテ側でも明示し、再分析へ誘導する -->
        <p v-if="stale"
            class="mb-2 border-l-[3px] border-check bg-check-bg px-[13px] py-[9px] text-[13px] leading-[21px] text-check-fg">
            このカルテは分析（{{ karte?.finishedAt }}）が見た材料から変わっています（<template
                v-if="changedText">追加・更新: {{ changedText }}</template><template
                v-if="changedText && removedText"> / </template><template
                v-if="removedText">削除: {{ removedText }}</template>）。
            <template v-if="removedText">削除された材料はカルテの根拠として残っているため、リンクをたどっても開けません。</template>
            右上の「再分析」で最新の材料を反映できます。
            <button type="button" @click="setTab('sources')"
                class="underline underline-offset-[3px] hover:text-conflict">材料を確認</button>
        </p>
        <!-- 判定しきれない範囲がある場合は、鮮度の帯とは別に必ず出す（黙って最新と見せない） -->
        <p v-if="karte?.freshness.unavailableReason" class="mb-2 text-[12px] text-ink-3">
            {{ karte.freshness.unavailableReason }}
        </p>
        <!-- 主張の並び順に重要度を使えていない場合、黙って平坦に並べずその事実を出す -->
        <p v-if="karte?.importanceNote" class="mb-2 text-[12px] text-ink-3">{{ karte.importanceNote }}</p>
        <KarteView :karte="karte" :company-id="companyId" :open-issues="openIssues" :coverage="coverage" />
        <!-- 面接後の導線: 回答を面接記録に入れて再分析すると「検証不能」が「整合/矛盾」に更新される -->
        <p class="mt-6 text-[12px] text-ink-3">
            面接を終えたら
            <button type="button" @click="setTab('sources', 'interview-record')"
                class="text-ink underline underline-offset-[3px] hover:text-conflict">面接記録を追加</button>
            → 再分析で、検証不能だった主張が面接での回答で更新されます。
        </p>
    </template>
    <div v-else class="border border-rule bg-surface px-6 py-10 text-center">
        <p class="text-[13px] leading-[22px] text-ink-2">
            <template v-if="run?.isRunning">
                分析を実行中です（数分かかります）。完了するとここにカルテが表示されます。
            </template>
            <template v-else-if="hasSources">
                まだ分析していません。右上の「分析実行」で主張検証を行います。逆質問は分析後に「面接準備」タブから生成できます。
            </template>
            <template v-else>
                「ソース」タブから求人票や口コミを投入してください。投入後、右上の「分析実行」でカルテを生成します。
            </template>
        </p>
    </div>
    </div>
</template>
