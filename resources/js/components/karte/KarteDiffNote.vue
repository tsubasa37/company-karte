<script setup lang="ts">
import { useRecommendation } from '@/composables/useRecommendation';
import type { KarteDiff } from '@/types/karte';

// 前回の分析との差分（再分析でループを回すと変化が見える）
defineProps<{ diff: KarteDiff }>();

// おすすめ度の色は composable が正。ここで色を書かない
const { textClass } = useRecommendation();
</script>

<template>
    <!-- 上下の罫で囲った帯にする（枠・地色は使わない） -->
    <div class="mt-3 border-t border-b border-rule py-[9px] text-[12px] text-ink-2">
        <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
            <span class="text-ink-3">前回から</span>
            <span v-if="diff.currRecommendation?.value !== diff.prevRecommendation?.value">
                おすすめ度
                <span class="font-serif" :class="textClass(diff.prevRecommendation?.value)">
                    {{ diff.prevRecommendation?.symbol ?? '—' }}
                </span>→<span class="font-serif text-[15px] font-semibold"
                    :class="textClass(diff.currRecommendation?.value)">
                    {{ diff.currRecommendation?.symbol ?? '—' }}
                </span>
            </span>
            <span v-else>おすすめ度 変化なし</span>
            <span>矛盾 <span class="tabular-nums">{{ diff.prev.contradict }}→{{ diff.curr.contradict }}</span></span>
            <!-- 判定割れは複数人で裏取りしたランにしか出ないため、両方0なら出さない -->
            <span v-if="diff.prev.split || diff.curr.split">
                判定割れ <span class="tabular-nums">{{ diff.prev.split }}→{{ diff.curr.split }}</span>
            </span>
            <span>検証不能 <span class="tabular-nums">{{ diff.prev.unverifiable }}→{{ diff.curr.unverifiable }}</span></span>
        </div>
        <!-- 人数が違うランを比べると、判定の変化に確定ルール（過半数の条件）の違いが混ざる -->
        <p v-if="diff.panelNote" class="mt-[6px] text-check-fg">{{ diff.panelNote }}</p>
        <!-- 件数の増減だけではどの主張の判定が変わったか分からないため、変化した主張を並べる -->
        <ul v-if="diff.changes.items.length" class="mt-[6px] list-disc pl-[18px] leading-[20px]">
            <li v-for="(c, i) in diff.changes.items" :key="i">
                <span class="text-ink">{{ c.claim }}</span>: {{ c.prevLabel }} →
                <span class="font-semibold text-ink">{{ c.currLabel }}</span>
            </li>
            <li v-if="diff.changes.more" class="text-ink-3">他{{ diff.changes.more }}件</li>
        </ul>
        <p v-else-if="diff.changes.unavailableReason" class="mt-[6px] text-ink-3">
            {{ diff.changes.unavailableReason }}
        </p>
        <p v-else class="mt-[6px] text-ink-3">判定が変わった主張はありません</p>
    </div>
</template>
