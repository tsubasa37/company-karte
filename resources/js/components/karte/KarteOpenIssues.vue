<script setup lang="ts">
import HelpTip from '@/components/HelpTip.vue';
import OpenIssueRow from '@/components/karte/OpenIssueRow.vue';
import EmptyNote from '@/components/shell/EmptyNote.vue';
import { HELP } from '@/constants/help';
import type { Option } from '@/types/company';
import type { OpenIssues } from '@/types/issue';
import { computed } from 'vue';

const props = defineProps<{
    openIssues: OpenIssues;
    statusOptions: Option[];
    /** 面接メモが未投入なら、消し込みが自動で走らないことを添える */
    hasInterviewNote: boolean;
}>();

// 並び順（未確認・未解消が先）は PHP が確定済み。ここでは表示の区切りに使うだけ
const pending = computed(() => props.openIssues.items.filter((i) => i.isPending));
const closed = computed(() => props.openIssues.items.filter((i) => !i.isPending));
</script>

<template>
    <section id="karte-issues" class="scroll-mt-24">
        <h2 class="border-b-2 border-ink pb-[5px] font-serif text-[19px] font-semibold text-ink">
            面接で確認する論点
            <HelpTip :help="HELP.openIssues" />
            <span class="ml-[10px] font-sans text-[12px] font-normal text-ink-3">
                未解消 {{ openIssues.pendingCount }} / 全{{ openIssues.items.length }}件
            </span>
        </h2>

        <EmptyNote v-if="!openIssues.items.length">
            まだ論点を追跡していません。「評価」タブの主張検証で 矛盾・判定割れ・検証不能 と出た行の「論点として追う」を押すと、
            ここに積まれます。追跡した論点は再分析でも消えず、面接での回答で消し込めます。
        </EmptyNote>

        <template v-else>
            <p v-if="openIssues.pendingCount > 0 && !hasInterviewNote"
                class="mt-[10px] text-[12px] leading-[20px] text-ink-3">
                面接メモを投入すると、この一覧に答えが出たかを自動で照合します（面接メモが無い間は照合しません）。
            </p>

            <ul v-if="pending.length" class="mt-[10px]">
                <OpenIssueRow v-for="issue in pending" :key="issue.id" :issue="issue"
                    :status-options="statusOptions" />
            </ul>

            <template v-if="closed.length">
                <h3 class="mt-[22px] border-t border-rule pt-[7px] font-serif text-[14px] font-bold text-ink-2">
                    解消済み・取り下げ
                    <span class="ml-[8px] font-sans text-[11px] font-normal text-ink-3">{{ closed.length }}件</span>
                </h3>
                <ul>
                    <OpenIssueRow v-for="issue in closed" :key="issue.id" :issue="issue"
                        :status-options="statusOptions" closed />
                </ul>
            </template>
        </template>
    </section>
</template>
