<script setup lang="ts">
import HelpTip from '@/components/HelpTip.vue';
import KarteSkipped from '@/components/karte/KarteSkipped.vue';
import VerdictTable from '@/components/karte/VerdictTable.vue';
import VerdictUnverifiableOnly from '@/components/karte/VerdictUnverifiableOnly.vue';
import { VERDICT_META } from '@/constants/verdict';
import type { P2Verdicts, Verdict, VerdictCounts } from '@/types/karte';
import { useVerdictGroups } from '@/composables/useVerdictGroups';
import { computed, toRef } from 'vue';
import { HELP } from '@/constants/help';

const props = defineProps<{
    p2: P2Verdicts | null;
    /** 件数は PHP で確定集計済み */
    counts: VerdictCounts;
    skippedReason: string | null;
    companyId: number;
    /** 論点として追跡するとき、どのランの P2 出力から本文を読むか */
    runId: number;
    /** このランに口コミの機械判定があるか（表に機械判定の列を足すか） */
    hasMachine: boolean;
}>();

// 表示グループ分け（判定 × 重要度）と、全件が検証不能かどうかの判定
const { attention, unverifiable, lowImportance, lowAttention, unverifiableOnly } = useVerdictGroups(
    toRef(props, 'p2'),
    toRef(props, 'counts'),
);

// 件数の文言と色は constants/verdict.ts（VERDICT_META）が正本。節ナビと同じ並び・同じ色を使う
const NAV_VERDICTS: Verdict[] = ['contradict', 'split', 'match', 'unverifiable'];
const countChips = computed(() =>
    NAV_VERDICTS
        // 判定割れは1人で裏取りしたランでは常に0。0件のときだけ列を出さない（従来どおり）
        .filter((v) => v !== 'split' || props.counts.split > 0)
        .map((v) => ({ key: v, label: VERDICT_META[v].label, cls: VERDICT_META[v].cls, n: props.counts[v] })),
);
</script>

<template>
    <KarteSkipped v-if="skippedReason" id="karte-verdicts" title="主張検証テーブル" :reason="skippedReason" />
    <section v-else-if="p2" id="karte-verdicts" class="scroll-mt-24">
        <h2 class="flex items-center gap-[6px] border-b-2 border-ink pb-[5px] font-serif text-[19px] font-semibold text-ink">
            主張検証テーブル
            <HelpTip :help="HELP.verdicts" />
        </h2>
        <p class="mt-2 text-[12px] leading-[20px] text-ink-2">求人票の主張を公式データ・口コミと突き合わせ。</p>

        <VerdictUnverifiableOnly v-if="unverifiableOnly" :verdicts="unverifiable" :low="lowImportance"
            :total="counts.unverifiable" :company-id="companyId" :run-id="runId" :has-machine="hasMachine" />

        <template v-else>
        <div class="mt-2 flex flex-wrap items-baseline gap-x-2 gap-y-1">
            <span v-for="c in countChips" :key="c.key" class="px-[6px] text-[11px] tabular-nums" :class="c.cls">
                {{ c.label }} {{ c.n }}
            </span>
            <span v-if="lowImportance.length" class="text-[11px] text-ink-3">
                （件数は重要度・低の {{ lowImportance.length }}件を含む。低は下に畳んで表示）
            </span>
        </div>

        <!--
            判定割れ は材料不足の 検証不能 と紛らわしい。次に取る行動が「材料を足す」ではなく
            「面接で聞く」であることを、割れが出たときだけ明示する
        -->
        <p v-if="counts.split"
            class="mt-[9px] border-l-[3px] border-split bg-split-bg px-3 py-2 text-[12px] leading-[19px] text-split-fg">
            判定割れ は、観点の違う判定者の間で解釈が分かれた主張です。材料を足しても解消しないため、面接で事実を確認してください。
        </p>

        <VerdictTable v-if="attention.length" :verdicts="attention" :company-id="companyId" :run-id="runId"
            :has-machine="hasMachine" />
        <p v-else-if="lowAttention" class="mt-3 text-[12px] leading-[20px] text-ink-3">
            矛盾・判定割れ・整合の判定は「重要度・低」の {{ lowAttention }}件のみです（下の折りたたみで確認できます）。
        </p>
        <p v-else class="mt-3 text-[12px] leading-[20px] text-ink-3">
            矛盾・整合と判定できた主張はまだありません（検証材料が足りない状態です）。
        </p>

        <!-- 検証不能は件数が多く壁になるため既定で畳む。何を投入すれば検証できるかは開いて確認 -->
        <details v-if="unverifiable.length" class="mt-4 border-t border-rule pt-[10px]">
            <summary class="cursor-pointer text-[13px] font-medium text-check hover:text-ink">
                検証不能 {{ unverifiable.length }}件（開くと一覧表示 — 材料を足すと 整合／矛盾 に変わります）
            </summary>
            <VerdictTable :verdicts="unverifiable" :company-id="companyId" :run-id="runId" :has-machine="hasMachine" />
        </details>

        <!-- 応募判断が変わらない主張（設立年・資本金・所在地・法定義務の制度など）。捨てずに畳む -->
        <details v-if="lowImportance.length" class="mt-[10px] border-t border-rule pt-[10px]">
            <summary class="cursor-pointer text-[13px] font-medium text-ink-2 hover:text-ink">
                重要度・低 {{ lowImportance.length }}件（設立年・所在地・法定義務の制度など。応募判断には影響しにくい主張）
            </summary>
            <VerdictTable :verdicts="lowImportance" :company-id="companyId" :run-id="runId" :has-machine="hasMachine" />
        </details>
        </template>
    </section>
</template>
