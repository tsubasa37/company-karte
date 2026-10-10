<script setup lang="ts">
import CompareSalaryCell from '@/components/company/CompareSalaryCell.vue';
import CompareTextCell from '@/components/company/CompareTextCell.vue';
import ClampText from '@/components/shell/ClampText.vue';
import { useRecommendation } from '@/composables/useRecommendation';
import type { CompareRow } from '@/types/company';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    r: CompareRow;
    /** 表の中での行位置。ゼブラの地色を決めるためだけに使う（sticky セルは透けないので自前で地を持つ） */
    index: number;
}>();

const { textClass, splitLabel } = useRecommendation();

/**
 * 条件が空の理由（「—」で4通りを同じに見せない）。
 * conditions が null なら求人票の有無で「未投入」と「未抽出（失敗も含む）」を分ける。
 */
const missing = (): string =>
    props.r.conditions !== null ? '記載なし' : props.r.hasConditionSource ? '未抽出' : '求人票未投入';

/** おすすめ度の記号と文言を分けて描く（記号だけ明朝で大きく出す）。分割の規則は composable が持つ */
const rec = computed(() => (props.r.recommendationLabel ? splitLabel(props.r.recommendationLabel) : null));

// 1行おきの地色。sticky な企業名セルにも同じ地を敷かないと、下の列が透ける
const zebra = computed(() => (props.index % 2 === 1 ? 'bg-surface-alt' : 'bg-surface'));
</script>

<template>
    <tr class="group hover:bg-bench" :class="zebra">
        <!-- 企業名は横スクロールしても固定。行ホバー色を sticky セルにも合わせる（透けさせない）。
             群の左端なので右罫は墨（群の境界の細罫と区別する） -->
        <th scope="row"
            class="sticky left-0 z-10 border-r border-b border-r-ink border-b-rule-thin px-4 py-[10px] text-left align-top font-normal group-hover:bg-bench"
            :class="zebra">
            <Link :href="`/companies/${r.id}`"
                class="font-serif text-[14px] font-semibold text-ink underline-offset-[3px] hover:text-conflict hover:underline">
                {{ r.name }}
            </Link>
            <!-- おすすめ度を色帯で持たせる。横スクロール中も「どの評価の行か」が固定列で分かる -->
            <span class="mt-[3px] block h-[3px] w-[34px] bg-current"
                :class="textClass(r.recommendation) || 'text-rule-input'"></span>
        </th>
        <td class="border-b border-rule-thin px-4 py-[10px]">
            <div v-if="rec" class="flex items-baseline gap-[6px] whitespace-nowrap"
                :class="textClass(r.recommendation)">
                <span v-if="rec.symbol" class="font-serif text-[16px] leading-none">{{ rec.symbol }}</span>
                <span class="text-[12px] font-semibold">{{ rec.text }}</span>
            </div>
            <span v-else class="text-[12px] text-ink-4">未分析</span>
            <!-- 旧ランには判定理由・懸念のキーが無い。黙って空にせず未保存と明示する -->
            <ClampText v-if="r.recommendationReason" class="mt-[5px] text-[11px] leading-[17px] text-ink-2"
                :text="r.recommendationReason" :lines="3" />
            <p v-else-if="r.analyzed && r.recommendationReason === null"
                class="mt-[5px] text-[11px] leading-[17px] text-ink-4">
                判定理由は未保存（再分析で表示されます）
            </p>
            <ClampText v-if="r.concern" class="mt-[5px] text-[11px] leading-[17px] text-check-fg"
                :text="`懸念: ${r.concern}`" :lines="3" />
            <p v-else-if="r.analyzed && r.concern === null" class="mt-[5px] text-[11px] leading-[17px] text-ink-4">
                懸念は未保存（再分析で表示されます）
            </p>
        </td>
        <td class="border-b border-rule-thin px-4 py-[10px] whitespace-nowrap">
            <template v-if="r.analyzed && r.mustTotal">
                <span class="font-mono text-[13px] tabular-nums"
                    :class="r.mustUnmet > 0 ? 'font-medium text-conflict' : 'text-ink'">
                    {{ r.mustMet }}/{{ r.mustTotal }}
                </span>
                <span v-if="r.mustUnmet > 0" class="ml-[6px] text-[11px] text-conflict tabular-nums">不足{{ r.mustUnmet }}</span>
            </template>
            <span v-else class="font-mono text-[13px] text-ink-4">—</span>
        </td>
        <td class="border-b border-rule-thin px-4 py-[10px] whitespace-nowrap">
            <!-- 抵触は絵文字をやめ、語＋色で示す（0/3 の形と見分けが付くように語を残す） -->
            <span v-if="r.ngHit > 0" class="text-[12px] font-semibold text-conflict tabular-nums">抵触{{ r.ngHit }}</span>
            <span v-else-if="r.ngTotal > 0" class="font-mono text-[13px] text-ink tabular-nums">0/{{ r.ngTotal }}</span>
            <!-- 旧ラン（ng_checks 未保存）を「該当なし」に見せない -->
            <span v-else-if="r.analyzed && !r.ngSaved" class="text-[11px] text-ink-4" title="再分析で表示されます">
                未保存
            </span>
            <span v-else class="font-mono text-[13px] text-ink-4">—</span>
            <!-- 判定不能はホバーに隠さず可視化する（clear と同じに見せない） -->
            <p v-if="r.ngUnknown > 0"
                class="mt-[5px] text-[11px] leading-[17px] whitespace-nowrap text-check tabular-nums">
                判定不能{{ r.ngUnknown }}
            </p>
        </td>
        <td class="border-b border-rule-thin px-4 py-[10px] whitespace-nowrap">
            <span v-if="r.analyzed && r.wantTotal" class="font-mono text-[13px] text-ink tabular-nums">
                {{ r.wantMet }}/{{ r.wantTotal }}
            </span>
            <span v-else class="font-mono text-[13px] text-ink-4">—</span>
        </td>
        <!-- 群「判定」の右端。次の群（求人票の条件）との境界に細罫を入れる -->
        <td class="border-r border-b border-r-rule-input border-b-rule-thin px-4 py-[10px] whitespace-nowrap">
            <template v-if="r.analyzed">
                <span class="text-[12px] tabular-nums"
                    :class="r.contradicts > 0 ? 'font-semibold text-conflict' : 'text-ink-4'">
                    矛盾{{ r.contradicts }}
                </span>
                <!-- 判定割れは複数人で裏取りした企業だけ出る（材料不足の検証不能とは意味が違うので分けて出す） -->
                <span v-if="r.splits" class="ml-2 text-[12px] font-semibold text-split tabular-nums">割れ{{ r.splits }}</span>
                <span class="ml-2 text-[12px] text-check tabular-nums">検証不能{{ r.unverifiables }}</span>
            </template>
            <span v-else class="font-mono text-[13px] text-ink-4">—</span>
        </td>
        <CompareSalaryCell :r="r" :missing="missing()" />
        <CompareTextCell class="max-w-[10rem]" :value="r.conditions?.location" :missing="missing()" />
        <CompareTextCell class="max-w-[12rem]" :value="r.conditions?.remote" :missing="missing()" />
        <!-- 群「求人票の条件」の右端。次の群（選考）との境界に細罫を入れる -->
        <CompareTextCell class="max-w-[14rem] border-r border-r-rule-input" :value="r.conditions?.holidays"
            :missing="missing()" />
        <td class="border-b border-rule-thin px-4 py-[10px] whitespace-nowrap">
            <template v-if="r.offerSalary !== null">
                <span class="font-mono text-[13px] font-medium text-ink tabular-nums">{{ r.offerSalary }}万</span>
                <span v-if="r.offerDelta !== null" class="ml-[6px] font-mono text-[11px] font-semibold tabular-nums"
                    :class="r.offerDelta >= 0 ? 'text-match' : 'text-conflict'">
                    {{ r.offerDelta >= 0 ? '+' : '' }}{{ r.offerDelta }}万
                </span>
                <!-- 固定残業込み等は差額の色表示だけでは実質が読めないため注記を添える -->
                <p v-if="r.offerCaveat"
                    class="mt-[5px] max-w-[15rem] text-[11px] leading-[17px] whitespace-normal text-check">
                    {{ r.offerCaveat }}
                </p>
            </template>
            <span v-else class="font-mono text-[13px] text-ink-4">—</span>
        </td>
        <td class="border-b border-rule-thin px-4 py-[10px] whitespace-nowrap">
            <span class="text-[12px] text-ink-2">{{ r.statusLabel }}</span>
            <p v-if="r.analyzedAt" class="mt-1 text-[11px] text-ink-3">
                分析 <span class="font-mono tabular-nums">{{ r.analyzedAt }}</span>
            </p>
        </td>
    </tr>
</template>
