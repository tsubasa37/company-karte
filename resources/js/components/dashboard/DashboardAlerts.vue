<script setup lang="ts">
import HelpTip from '@/components/HelpTip.vue';
import type { AlertBlock, DashboardCompany } from '@/types/company';
import type { StaleCompany } from '@/types/dashboard';
import { Link } from '@inertiajs/vue3';
import { HELP } from '@/constants/help';

// 並び順・上位件数の切り出し・残数は PHP（BuildDashboardAction）で確定済み。ここは描画のみ
defineProps<{
    needsSources: AlertBlock<DashboardCompany>;
    /** missing は「なぜ判定保留なのか」（不足材料の1行。PHP が確定） */
    holds: AlertBlock<DashboardCompany & { missing: string | null }>;
    stale: AlertBlock<StaleCompany>;
    profileChanged: AlertBlock<DashboardCompany>;
    laborAlerts: AlertBlock<DashboardCompany & { note: string }>;
    /** 台帳の通し番号（確定は Dashboard.vue） */
    nos: {
        needsSources: number;
        stale: number;
        profileChanged: number;
        holds: number;
        laborAlerts: number;
    };
}>();

// 「進める」「待ち」の節は左罫を引かず、通し番号の偶奇でゼブラにする
const zebra = (no: number) => (no % 2 === 0 ? 'bg-surface-alt' : 'bg-surface');
</script>

<template>
    <!-- 上から「今日やること」の優先順: 材料を入れる → 分析し直す → 材料を足す → 確認する -->
    <section v-if="needsSources.total"
        class="border-b border-b-rule-thin border-l-[3px] border-l-transparent py-[13px] pr-5 pl-[17px]"
        :class="zebra(nos.needsSources)">
        <div class="grid grid-cols-[2.4rem_minmax(0,1fr)] gap-3">
            <div class="pt-px text-right">
                <div class="font-mono text-[11px] tabular-nums text-ink-4">{{ nos.needsSources }}</div>
                <div class="font-serif text-[15px] leading-[1.2] text-ink" aria-hidden="true">·</div>
            </div>
            <div class="min-w-0">
                <div class="flex flex-wrap items-baseline gap-2">
                    <h2 class="font-serif text-[15px] font-semibold text-ink">
                        ソース未投入（{{ needsSources.total }}件・まずここから）
                    </h2>
                    <HelpTip :help="HELP.needsSources" />
                </div>
                <p class="mt-[5px] text-[12px] leading-5 text-ink-2">
                    求人票や口コミを1件も取り込んでいない企業です。ページを開いて拡張機能で取り込むと分析できます。
                </p>
                <ul class="mt-2 flex flex-wrap gap-x-[18px] gap-y-[5px]">
                    <li v-for="c in needsSources.items" :key="c.id">
                        <Link :href="`/companies/${c.id}`"
                            class="text-[13px] font-medium text-ink hover:text-conflict hover:underline">{{ c.name }}</Link>
                    </li>
                    <li v-if="needsSources.more" class="text-[12px] text-ink-3">他{{ needsSources.more }}件</li>
                </ul>
            </div>
        </div>
    </section>

    <section v-if="stale.total"
        class="border-b border-b-rule-thin border-l-[3px] border-l-transparent py-[13px] pr-5 pl-[17px]"
        :class="zebra(nos.stale)">
        <div class="grid grid-cols-[2.4rem_minmax(0,1fr)] gap-3">
            <div class="pt-px text-right">
                <div class="font-mono text-[11px] tabular-nums text-ink-4">{{ nos.stale }}</div>
                <div class="font-serif text-[15px] leading-[1.2] text-ink" aria-hidden="true">·</div>
            </div>
            <div class="min-w-0">
                <div class="flex flex-wrap items-baseline gap-2">
                    <h2 class="font-serif text-[15px] font-semibold text-ink">
                        再分析の候補（{{ stale.total }}件・分析後に材料が変わった企業）
                    </h2>
                    <HelpTip :help="HELP.stale" />
                </div>
                <ul class="mt-2 flex flex-wrap gap-x-[18px] gap-y-[5px]">
                    <li v-for="c in stale.items" :key="c.id" class="flex flex-wrap items-baseline gap-2">
                        <Link :href="`/companies/${c.id}`"
                            class="text-[13px] font-medium text-ink hover:text-conflict hover:underline">{{ c.name }}</Link>
                        <span class="text-[12px] leading-[19px] text-ink-2">
                            <template v-if="c.newSources">ソース+{{ c.newSources }}件</template>
                            <template v-if="c.newSources && c.newMetrics"> / </template>
                            <template v-if="c.newMetrics">公的数値+{{ c.newMetrics }}件</template>
                            <template v-if="(c.newSources || c.newMetrics) && (c.removedSources || c.removedMetrics)"> / </template>
                            <template v-if="c.removedSources">ソース-{{ c.removedSources }}件</template>
                            <template v-if="c.removedSources && c.removedMetrics"> / </template>
                            <template v-if="c.removedMetrics">公的数値-{{ c.removedMetrics }}件</template>
                        </span>
                    </li>
                    <li v-if="stale.more" class="text-[12px] text-ink-3">他{{ stale.more }}件</li>
                </ul>
            </div>
        </div>
    </section>

    <section v-if="profileChanged.total"
        class="border-b border-b-rule-thin border-l-[3px] border-l-transparent py-[13px] pr-5 pl-[17px]"
        :class="zebra(nos.profileChanged)">
        <div class="grid grid-cols-[2.4rem_minmax(0,1fr)] gap-3">
            <div class="pt-px text-right">
                <div class="font-mono text-[11px] tabular-nums text-ink-4">{{ nos.profileChanged }}</div>
                <div class="font-serif text-[15px] leading-[1.2] text-ink" aria-hidden="true">·</div>
            </div>
            <div class="min-w-0">
                <h2 class="font-serif text-[15px] font-semibold text-ink">
                    プロファイル更新後に再分析していない企業（{{ profileChanged.total }}件）
                </h2>
                <p class="mt-[5px] text-[12px] leading-5 text-ink-2">
                    must/want/NG や軸を変えると適合評価の前提が変わります。再分析すると新しい前提で判定されます。
                </p>
                <ul class="mt-2 flex flex-wrap gap-x-[18px] gap-y-[5px]">
                    <li v-for="c in profileChanged.items" :key="c.id">
                        <Link :href="`/companies/${c.id}`"
                            class="text-[13px] font-medium text-ink hover:text-conflict hover:underline">{{ c.name }}</Link>
                    </li>
                    <li v-if="profileChanged.more" class="text-[12px] text-ink-3">他{{ profileChanged.more }}件</li>
                </ul>
            </div>
        </div>
    </section>

    <section v-if="holds.total"
        class="border-b border-b-rule-thin border-l-[3px] border-l-transparent py-[13px] pr-5 pl-[17px]"
        :class="zebra(nos.holds)">
        <div class="grid grid-cols-[2.4rem_minmax(0,1fr)] gap-3">
            <div class="pt-px text-right">
                <div class="font-mono text-[11px] tabular-nums text-ink-4">{{ nos.holds }}</div>
                <div class="font-serif text-[15px] leading-[1.2] text-ink-3" aria-hidden="true">―</div>
            </div>
            <div class="min-w-0">
                <h2 class="font-serif text-[15px] font-semibold text-ink-3">
                    判定保留（材料不足）の企業（{{ holds.total }}件）
                </h2>
                <p class="mt-[5px] text-[12px] leading-5 text-ink-2">
                    求人票などのソースを追加して再分析すると、おすすめ度が確定します。
                </p>
                <!-- 「なぜ保留か」を企業を開かずに分かるよう、不足材料を各行に添える -->
                <ul class="mt-2 flex flex-col gap-1.5">
                    <li v-for="c in holds.items" :key="c.id" class="flex flex-wrap items-baseline gap-2">
                        <Link :href="`/companies/${c.id}`"
                            class="text-[13px] font-medium text-ink hover:text-conflict hover:underline">{{ c.name }}</Link>
                        <span v-if="c.missing" class="text-[12px] leading-[19px] text-ink-2">{{ c.missing }}</span>
                    </li>
                    <li v-if="holds.more" class="text-[12px] text-ink-3">他{{ holds.more }}件</li>
                </ul>
            </div>
        </div>
    </section>

    <section v-if="laborAlerts.total"
        class="border-b border-b-rule-thin border-l-[3px] border-l-conflict bg-surface py-[13px] pr-5 pl-[17px]">
        <div class="grid grid-cols-[2.4rem_minmax(0,1fr)] gap-3">
            <div class="pt-px text-right">
                <div class="font-mono text-[11px] tabular-nums text-ink-4">{{ nos.laborAlerts }}</div>
                <div class="font-serif text-[15px] leading-[1.2] text-conflict" aria-hidden="true">△</div>
            </div>
            <div class="min-w-0">
                <h2 class="font-serif text-[15px] font-semibold text-conflict">
                    労基関係法令違反の公表リストに社名一致（{{ laborAlerts.total }}件・要確認）
                </h2>
                <p class="mt-[5px] text-[12px] leading-5 text-ink-2">
                    同名の別法人の可能性もあるため、出典の原文を必ず確認してください。
                </p>
                <ul class="mt-2 flex flex-col gap-1.5">
                    <li v-for="c in laborAlerts.items" :key="c.id" class="flex flex-wrap items-baseline gap-2">
                        <Link :href="`/companies/${c.id}`"
                            class="text-[13px] font-medium text-ink hover:text-conflict hover:underline">{{ c.name }}</Link>
                        <span class="text-[12px] leading-[19px] text-conflict">{{ c.note }}…</span>
                    </li>
                    <li v-if="laborAlerts.more" class="text-[12px] text-ink-3">他{{ laborAlerts.more }}件</li>
                </ul>
            </div>
        </div>
    </section>
</template>
