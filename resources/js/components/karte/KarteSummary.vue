<script setup lang="ts">
import CheckList from '@/components/karte/CheckList.vue';
import HelpTip from '@/components/HelpTip.vue';
import KarteDiffNote from '@/components/karte/KarteDiffNote.vue';
import KarteSkipped from '@/components/karte/KarteSkipped.vue';
import { useRecommendation } from '@/composables/useRecommendation';
import type { KarteDiff, P5Summary, RecommendationView } from '@/types/karte';
import { HELP } from '@/constants/help';

defineProps<{
    p5: P5Summary | null;
    recommendation: RecommendationView | null;
    recommendationReason: string | null;
    diff: KarteDiff | null;
    skippedReason: string | null;
    /** 判定割れがあるときの注意書き（PHPが確定。無ければ null） */
    checkSplitNote: string | null;
}>();

// 記号は明朝の ◎ △ ―。◎=充足 / △=未充足 / ―=判定に必要な材料が無い
const checkMeta = {
    met: { symbol: '◎', cls: 'text-match' },
    unmet: { symbol: '△', cls: 'text-conflict' },
    unknown: { symbol: '―', cls: 'text-ink-3' },
};

// NG条件は意味が逆（該当=悪い）なので記号の割り当ても逆にする
const ngMeta = {
    hit: { symbol: '△', cls: 'text-conflict' },
    clear: { symbol: '◎', cls: 'text-match' },
    unknown: { symbol: '―', cls: 'text-ink-3' },
};

// ラベル・記号はサーバー（Recommendation enum）から来る。ここでは色だけ決める
const { badgeClass } = useRecommendation();
</script>

<template>
    <KarteSkipped v-if="skippedReason" id="karte-summary" title="総合所見" :reason="skippedReason" />
    <section v-else-if="p5" id="karte-summary" class="scroll-mt-24">
        <h2 class="flex flex-wrap items-center gap-[6px] border-b-2 border-ink pb-[5px] font-serif text-[19px] font-semibold text-ink">
            総合所見
            <HelpTip :help="HELP.recommendation" />
            <span v-if="recommendation" class="rounded-[2px] px-2 py-[1px] font-sans text-[12px] font-medium"
                :class="badgeClass(recommendation.value)">
                {{ recommendation.label }}
            </span>
        </h2>
        <!-- 判定はAIではなくPHPのルールで確定している。その根拠を明示する -->
        <p v-if="recommendationReason" class="mt-[10px] text-[12px] leading-[19px] text-ink-2">
            判定の理由: {{ recommendationReason }}
        </p>
        <KarteDiffNote v-if="diff" :diff="diff" />
        <ul class="mt-[14px] list-disc pl-[18px] font-serif text-[15px] leading-[27px] text-ink">
            <li v-for="(line, i) in p5.summary_lines" :key="i">{{ line }}</li>
        </ul>
        <!-- 魅力/懸念（p5 が返しているのに未表示だったフィールド） -->
        <div v-if="p5.appeal || p5.concern" class="mt-4 grid gap-4 md:grid-cols-2">
            <div v-if="p5.appeal" class="border-l-[3px] border-match pl-3">
                <div class="text-[11px] tracking-[.06em] text-match">魅力</div>
                <p class="mt-1 text-[13px] leading-[22px] text-ink">{{ p5.appeal }}</p>
            </div>
            <div v-if="p5.concern" class="border-l-[3px] border-check pl-3">
                <div class="text-[11px] tracking-[.06em] text-check">懸念</div>
                <p class="mt-1 text-[13px] leading-[22px] text-ink">{{ p5.concern }}</p>
            </div>
        </div>
        <!--
            個別条件の充足はPHPで検証できない（evidence が自由文で照合できない）。
            おすすめ度は判定割れで Mid に落ちるが個別条件は充足のまま見えるので、根拠の確認を促す
        -->
        <p v-if="checkSplitNote"
            class="mt-[14px] border-l-[3px] border-split bg-split-bg px-3 py-2 text-[12px] leading-[19px] text-split-fg">
            {{ checkSplitNote }}
        </p>
        <div class="mt-[18px] grid gap-[22px] md:grid-cols-3">
            <CheckList v-if="p5.must_checks?.length" title="必須条件" :items="p5.must_checks" :meta="checkMeta" />
            <CheckList v-if="p5.want_checks?.length" title="歓迎条件" :items="p5.want_checks" :meta="checkMeta" />
            <!-- ng_checks 導入(2026-07)以前のランには存在しない -->
            <CheckList v-if="p5.ng_checks?.length" title="NG条件（該当したら減点）" :items="p5.ng_checks" :meta="ngMeta" />
        </div>
    </section>
</template>
