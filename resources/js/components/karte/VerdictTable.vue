<script setup lang="ts">
import IssueTrackButton from '@/components/karte/IssueTrackButton.vue';
import MachineReviewCell from '@/components/karte/MachineReviewCell.vue';
import VerdictConsensusNote from '@/components/karte/VerdictConsensusNote.vue';
import ClampText from '@/components/shell/ClampText.vue';
import { VERDICT_META } from '@/constants/verdict';
import type { P2Verdicts } from '@/types/karte';
import { Link } from '@inertiajs/vue3';

defineProps<{
    verdicts: P2Verdicts['verdicts'];
    companyId: number;
    /** 論点として追跡するとき、どのランの P2 出力から本文を読むか */
    runId: number;
    /** ランに口コミの機械判定があるときだけ列を足す（無いランでは表の見た目を変えない） */
    hasMachine: boolean;
}>();
</script>

<template>
    <div class="mt-[14px] overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr class="border-b border-ink text-left">
                    <th scope="col" :class="hasMachine ? 'w-[22%]' : 'w-[26%]'" class="pr-[14px] pb-[7px] text-[11px] font-medium tracking-[.08em] text-ink-3">主張</th>
                    <th scope="col" :class="hasMachine ? 'w-[15%]' : 'w-[20%]'" class="pr-[14px] pb-[7px] text-[11px] font-medium tracking-[.08em] text-ink-3">判定</th>
                    <th v-if="hasMachine" scope="col" class="w-[18%] pr-[14px] pb-[7px] text-[11px] font-medium tracking-[.08em] text-ink-3">口コミ側（機械判定）</th>
                    <!-- 何と突き合わせた判定なのかが分からないと根拠を検証できない -->
                    <th scope="col" :class="hasMachine ? 'w-[18%]' : 'w-[24%]'" class="pr-[14px] pb-[7px] text-[11px] font-medium tracking-[.08em] text-ink-3">突合先</th>
                    <th scope="col" class="pb-[7px] text-[11px] font-medium tracking-[.08em] text-ink-3">根拠</th>
                    <!-- 面接で確認する論点として追跡するかどうか -->
                    <th scope="col" class="w-[92px] pb-[7px] text-[11px] font-medium tracking-[.08em] text-ink-3">論点</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(v, i) in verdicts" :key="i" class="border-b border-rule-thin align-top last:border-0">
                    <td class="py-3 pr-[14px]">
                        <div class="font-serif text-[14px] leading-[23px] text-ink">{{ v.claim }}</div>
                        <!-- 主張の出所（求人票のどの記載か）。自由文なので長文は畳み、その場で全文に開く -->
                        <ClampText v-if="v.source" class="mt-1 text-[11px] leading-[17px] text-ink-3"
                            :text="`出所: ${v.source}`" :lines="2" />
                        <p v-else class="mt-1 text-[11px] leading-[17px] text-ink-3">出所の記載なし</p>
                    </td>
                    <td class="py-3 pr-[14px]">
                        <span class="inline-block px-2 py-[2px] text-[12px] font-medium whitespace-nowrap"
                            :class="VERDICT_META[v.verdict]?.cls">
                            {{ VERDICT_META[v.verdict]?.label ?? v.verdict }}
                        </span>
                        <VerdictConsensusNote v-if="v.consensus" :consensus="v.consensus" :verdict="v.verdict" />
                    </td>
                    <td v-if="hasMachine" class="py-3 pr-[14px] text-[12px] leading-[20px]">
                        <MachineReviewCell :machine="v.machine" />
                    </td>
                    <td class="py-3 pr-[14px] text-[12px] leading-[20px] text-ink-2">
                        <!--
                            割れた行の突合先は代表1人ぶんでしかない。観点が違えば見た材料も違うので、
                            ここに1人分だけ出すと「全員がこの材料を見た」と読めてしまう
                        -->
                        <p v-if="v.verdict === 'split'" class="text-[11px] text-ink-3">
                            判定者ごとに異なります（判定欄の内訳を参照）
                        </p>
                        <template v-else>
                            <ClampText v-if="v.checked_against" :text="v.checked_against" :lines="3" />
                            <p v-else class="text-ink-3">突合材料なし</p>
                            <!-- 材料IDがあれば元のソースへ辿れる。source_ids 導入前のランはキー自体が無い -->
                            <div v-if="v.source_ids?.length" class="mt-[6px] flex flex-wrap gap-[5px]">
                                <Link v-for="id in v.source_ids" :key="id" :href="`/sources/${id}`"
                                    class="rounded-[2px] border border-rule-input px-[5px] py-px font-mono text-[11px] text-ink-2 hover:text-conflict">
                                    #{{ id }}
                                </Link>
                            </div>
                            <p v-else-if="v.source_ids === undefined" class="mt-[6px] text-[11px] text-ink-3">
                                材料リンクは未保存（再分析で表示されます）
                            </p>
                        </template>
                    </td>
                    <td class="py-3 text-[12px] leading-[20px] text-ink-2">
                        <!--
                            判定が割れた行の rationale は代表1人ぶんの根拠なので、ここに出すと
                            「整合と書いてあるのに判定は割れ」に見える。根拠は判定列の内訳（全員ぶん）で見せる
                        -->
                        <span v-if="v.verdict === 'split'" class="text-[11px] text-ink-3">
                            判定者ごとに根拠が異なります（判定欄の内訳を参照）
                        </span>
                        <template v-else>{{ v.rationale }}</template>
                    </td>
                    <td class="py-3 pl-[10px] align-top">
                        <IssueTrackButton :company-id="companyId" :run-id="runId" :source-index="v.sourceIndex"
                            :issue-id="v.issueId" :issue-status="v.issueStatus" :verdict="v.verdict" />
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
