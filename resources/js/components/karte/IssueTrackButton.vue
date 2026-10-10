<script setup lang="ts">
import type { IssueStatus } from '@/types/issue';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    companyId: number;
    /** 論点の本文はこのランの P2 出力から読む（画面からは添字だけ送る） */
    runId: number;
    /**
     * 並べ替える前の（保存済み output_json の中での）添字。
     * 表示は判定・重要度で並べ替えてあるため、行番号を送ると別の主張が登録される。
     * 導入前のランには無いので、その場合は追跡できないことを明示する。
     */
    sourceIndex?: number;
    /** 既に追跡している場合の open_issues.id */
    issueId?: number | null;
    /** 追跡中の論点の状態（未追跡は null） */
    issueStatus?: IssueStatus | null;
    verdict: string;
}>();

const posting = ref(false);

const track = () => {
    if (props.sourceIndex === undefined) {
        return;
    }
    posting.value = true;
    router.post(
        `/companies/${props.companyId}/issues`,
        { run_id: props.runId, verdict_index: props.sourceIndex },
        { preserveScroll: true, onFinish: () => (posting.value = false) },
    );
};

const untrack = () => {
    posting.value = true;
    router.delete(`/issues/${props.issueId}`, {
        preserveScroll: true,
        onFinish: () => (posting.value = false),
    });
};
</script>

<template>
    <span v-if="issueId" class="inline-flex items-baseline gap-[7px]">
        <a href="#karte-issues"
            class="font-mono text-[11px] whitespace-nowrap text-ink-3 underline underline-offset-[3px] hover:text-conflict">
            追跡中
        </a>
        <!--
            解除は「未確認」の論点だけ。面接での回答が根拠として紐づいた論点（解消・未解消等）は
            テーブルから消させない（論点節でも解消済みには「追跡をやめる」を出していない）
        -->
        <button v-if="issueStatus === 'open'" type="button"
            class="text-[11px] whitespace-nowrap text-ink-4 hover:text-conflict disabled:opacity-50" :disabled="posting"
            @click="untrack">
            やめる
        </button>
    </span>
    <!-- 整合は面接で確認する必要がないので追跡させない（可否の正は PHP の TrackOpenIssueAction） -->
    <template v-else-if="verdict !== 'match'">
        <button v-if="sourceIndex !== undefined" type="button"
            class="rounded-[2px] border border-ink btn-sm whitespace-nowrap text-ink hover:bg-bench disabled:opacity-50"
            :disabled="posting" @click="track">
            論点として追う
        </button>
        <span v-else class="text-[11px] text-ink-4">再分析で追跡可</span>
    </template>
</template>
