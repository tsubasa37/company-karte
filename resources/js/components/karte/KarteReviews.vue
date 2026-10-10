<script setup lang="ts">
import KarteSkipped from '@/components/karte/KarteSkipped.vue';
import type { P4Reviews } from '@/types/karte';

defineProps<{ p4: P4Reviews | null; skippedReason: string | null }>();
</script>

<template>
    <KarteSkipped v-if="skippedReason" id="karte-reviews" title="口コミ要約" :reason="skippedReason" />
    <section v-else-if="p4" id="karte-reviews" class="scroll-mt-24">
        <h2 class="border-b-2 border-ink pb-[5px] font-serif text-[19px] font-semibold text-ink">口コミ要約</h2>
        <!-- 件数はAIの作文（material_summary）でなくPHP確定値を正とする。上限で未投入があれば隠さない -->
        <p v-if="p4.material_meta" class="mt-2 text-[12px] tabular-nums text-ink-3">
            口コミ {{ p4.material_meta.total }}件中 {{ p4.material_meta.included }}件を材料に投入
            <span v-if="p4.material_meta.omitted > 0" class="text-check">
                （文字数上限のため {{ p4.material_meta.omitted }}件は未投入）
            </span>
        </p>
        <p class="mt-2 font-serif text-[14px] leading-[25px] text-ink">{{ p4.material_summary }}</p>
        <div class="mt-4 grid gap-[22px] md:grid-cols-2">
            <div v-if="p4.strong_points?.length">
                <div class="border-b border-match pb-1 text-[12px] font-semibold text-match">複数一致（強い根拠）</div>
                <ul class="mt-2 list-disc pl-[18px] text-[13px] leading-[23px] text-ink">
                    <li v-for="(s, i) in p4.strong_points" :key="i">{{ s }}</li>
                </ul>
            </div>
            <div v-if="p4.split_points?.length">
                <div class="border-b border-split pb-1 text-[12px] font-semibold text-split">在籍/退職で割れた点</div>
                <ul class="mt-2 list-disc pl-[18px] text-[13px] leading-[23px] text-ink">
                    <li v-for="(s, i) in p4.split_points" :key="i">{{ s }}</li>
                </ul>
            </div>
            <div v-if="p4.time_changes?.length">
                <div class="border-b border-ink pb-1 text-[12px] font-semibold text-ink">
                    時期による変化（古い口コミ → 最近の口コミ）
                </div>
                <ul class="mt-2 list-disc pl-[18px] text-[13px] leading-[23px] text-ink">
                    <li v-for="(s, i) in p4.time_changes" :key="i">{{ s }}</li>
                </ul>
            </div>
            <!-- 極端な声も隠さず参考として出す（判断はユーザーに委ねる） -->
            <div v-if="p4.weak_or_extreme?.length">
                <div class="border-b border-rule pb-1 text-[12px] font-semibold text-ink-3">
                    極端・偏りの可能性がある声（参考）
                </div>
                <ul class="mt-2 list-disc pl-[18px] text-[13px] leading-[23px] text-ink-3">
                    <li v-for="(s, i) in p4.weak_or_extreme" :key="i">{{ s }}</li>
                </ul>
            </div>
        </div>
        <p class="mt-[14px] text-[11px] leading-[18px] text-ink-3">{{ p4.note }}</p>
    </section>
</template>
