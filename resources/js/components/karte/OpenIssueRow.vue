<script setup lang="ts">
import { ISSUE_STATUS_CLS } from '@/constants/issue';
import type { Option } from '@/types/company';
import type { OpenIssue } from '@/types/issue';
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    issue: OpenIssue;
    statusOptions: Option[];
    /** 解消済み・取り下げの行は畳んだ見た目にする（追跡をやめるボタンも出さない） */
    closed?: boolean;
}>();

const busy = ref(false);
// 記録の削除は2段階確認（他の削除と同じ型）。「追跡をやめた」状態にするのはプルダウン側で、こちらは記録ごと消す
const confirmingDelete = ref(false);

const changeStatus = (event: Event) => {
    busy.value = true;
    router.patch(
        `/issues/${props.issue.id}`,
        { status: (event.target as HTMLSelectElement).value },
        { preserveScroll: true, onFinish: () => (busy.value = false) },
    );
};

const destroy = () => {
    busy.value = true;
    router.delete(`/issues/${props.issue.id}`, { preserveScroll: true, onFinish: () => (busy.value = false) });
};
</script>

<template>
    <li class="grid grid-cols-[auto_1fr_auto] items-start gap-3 border-b border-rule-thin py-[10px] last:border-0">
        <span class="px-2 py-[2px] font-mono text-[11px] whitespace-nowrap" :class="ISSUE_STATUS_CLS[issue.status]">
            {{ issue.statusLabel }}
        </span>

        <div>
            <p class="leading-[23px] text-ink" :class="closed ? 'text-[13px] text-ink-2' : 'font-serif text-[14px]'">
                {{ issue.claim }}
            </p>
            <p v-if="!closed" class="mt-[3px] text-[11.5px] leading-[18px] text-ink-3">
                追跡開始時: <span class="text-ink-2">{{ issue.verdictLabel }}</span>
                <template v-if="issue.rationale"> — {{ issue.rationale }}</template>
            </p>
            <!-- 状態を決めた根拠（面接記録からの引用、または手入力のメモ）。無ければ出さない -->
            <p v-if="issue.resolutionNote"
                class="mt-[5px] border-l-2 border-rule-input bg-bench px-[9px] py-[5px] text-[11.5px] leading-[19px] whitespace-pre-line text-ink-2">
                {{ issue.resolutionNote }}
                <Link v-if="issue.resolvedSourceId" :href="`/sources/${issue.resolvedSourceId}`"
                    class="ml-[6px] font-mono text-[11px] text-ink-3 underline underline-offset-[3px] hover:text-conflict">
                    #{{ issue.resolvedSourceId }}
                </Link>
            </p>
            <p v-if="closed && issue.statusUpdatedAt" class="mt-[3px] font-mono text-[11px] tabular-nums text-ink-4">
                {{ issue.statusUpdatedAt }}
            </p>
        </div>

        <div class="flex flex-col items-end gap-[5px]">
            <select class="border border-rule-input bg-white px-[6px] py-[3px] text-[11.5px]" :value="issue.status"
                :aria-label="`「${issue.claim}」の状態`" :disabled="busy" @change="changeStatus">
                <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
            <button v-if="!closed && !confirmingDelete" type="button" class="text-[11px] text-ink-3 hover:text-conflict"
                :disabled="busy" @click="confirmingDelete = true">
                記録を削除…
            </button>
            <div v-if="confirmingDelete"
                class="max-w-[16rem] border-l-[3px] border-conflict bg-conflict-bg px-[9px] py-[7px] text-[11.5px] leading-[18px] text-conflict-fg">
                <p>この論点の記録を削除します。面接メモとの照合結果も消え、元に戻せません。追跡だけやめるなら状態を「追跡をやめた」にしてください。</p>
                <div class="mt-[6px] flex gap-[6px]">
                    <button type="button" class="rounded-[2px] bg-conflict px-[8px] py-[3px] text-[11px] font-medium text-white hover:bg-conflict-fg"
                        :disabled="busy" @click="destroy">削除する</button>
                    <button type="button" class="rounded-[2px] border border-rule-input bg-white px-[8px] py-[3px] text-[11px] text-ink hover:bg-bench"
                        :disabled="busy" @click="confirmingDelete = false">やめる</button>
                </div>
            </div>
        </div>
    </li>
</template>
