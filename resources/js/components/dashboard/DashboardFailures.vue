<script setup lang="ts">
import type { ErrorCompanyAlert, FailedJobAlert, FailedRunAlert } from '@/types/company';
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    failedRuns: FailedRunAlert[];
    errorCompanies: ErrorCompanyAlert[];
    failedJobs: FailedJobAlert[];
    queueStalled: string | null;
    /** 台帳の通し番号（左余白に出す。0 なら非表示扱い。確定は Dashboard.vue） */
    no: number;
}>();

const retry = (companyId: number, runId: number) =>
    router.post(`/companies/${companyId}/runs/${runId}/retry`, {}, { preserveScroll: true });

const forgetJob = (id: number) => router.delete(`/failed-jobs/${id}`, { preserveScroll: true });

// 見出しの件数。滞留（queueStalled）は件数ではなく注記なので数えない
const total = computed(() => props.failedJobs.length + props.failedRuns.length + props.errorCompanies.length);
</script>

<template>
    <!-- 失敗系が1件でもあるときだけ描画する（空箱を常設しない） -->
    <section v-if="failedRuns.length || errorCompanies.length || failedJobs.length || queueStalled"
        class="border-b border-b-rule-thin border-l-[3px] border-l-conflict bg-surface py-[13px] pr-5 pl-[17px]">
        <div class="grid grid-cols-[2.4rem_minmax(0,1fr)] gap-3">
            <div class="pt-px text-right">
                <div class="font-mono text-[11px] tabular-nums text-ink-4">{{ no }}</div>
                <div class="font-serif text-[15px] leading-[1.2] text-conflict" aria-hidden="true">△</div>
            </div>
            <div class="min-w-0">
                <h2 class="font-serif text-[15px] font-semibold text-conflict">要対応（{{ total }}件）</h2>
                <p v-if="queueStalled" class="mt-[5px] text-[12px] leading-5 text-ink-2">{{ queueStalled }}</p>
                <ul class="mt-2 flex flex-col gap-1.5">
                    <template v-if="failedJobs.length">
                        <li class="text-[11px] text-ink-3">キューで失敗したジョブ（{{ failedJobs.length }}件）</li>
                        <li v-for="j in failedJobs" :key="j.id" class="flex flex-wrap items-baseline gap-2">
                            <span class="text-[13px] font-medium text-ink">{{ j.jobName }}</span>
                            <span class="text-[12px] leading-[19px] text-conflict">
                                <template v-if="j.failedAt">（{{ j.failedAt }}）</template>{{ j.excerpt }}
                            </span>
                            <button @click="forgetJob(j.id)"
                                class="rounded-[2px] border border-conflict-border bg-white btn-sm text-conflict hover:bg-conflict-bg">
                                削除
                            </button>
                        </li>
                    </template>
                    <li v-for="c in failedRuns" :key="`run-${c.id}`" class="flex flex-wrap items-baseline gap-2">
                        <Link :href="`/companies/${c.id}`"
                            class="text-[13px] font-medium text-ink hover:text-conflict hover:underline">{{ c.name }}</Link>
                        <span class="text-[12px] leading-[19px] text-conflict">
                            分析失敗<template v-if="c.failedAt">（{{ c.failedAt }}）</template>: {{ c.error }}
                        </span>
                        <button @click="retry(c.id, c.runId)"
                            class="rounded-[2px] border border-conflict-border bg-white btn-sm text-conflict hover:bg-conflict-bg">
                            失敗したステップから再開
                        </button>
                    </li>
                    <li v-for="c in errorCompanies" :key="`${c.id}-${c.kind}`" class="flex flex-wrap items-baseline gap-2">
                        <Link :href="`/companies/${c.id}`"
                            class="text-[13px] font-medium text-ink hover:text-conflict hover:underline">{{ c.name }}</Link>
                        <span class="text-[12px] leading-[19px] text-conflict">{{ c.kind }}に失敗: {{ c.error }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
