<script setup lang="ts">
import type { NewsScreeningStatus, NewsScreenResult } from '@/types/news';
import { useForm } from '@inertiajs/vue3';

const props = defineProps<{
    companyId: number;
    screening: NewsScreeningStatus;
    /** 判定ボタンの結果。押したときだけ flash で届く */
    result: NewsScreenResult | null;
}>();

// 見るたびには判定しない。押したときだけ未判定の記事を一括判定する
const form = useForm({});
const run = () => form.post(`/companies/${props.companyId}/news/screen`, { preserveScroll: true });
</script>

<template>
    <div v-if="screening.enabled" class="mt-3">
        <button type="button" @click="run" :disabled="form.processing || screening.unscreened === 0"
            class="rounded-[2px] border border-rule-input bg-white btn-sm text-ink hover:bg-bench disabled:opacity-50">
            {{ form.processing ? '判定中…' : `機械判定で選別（未判定 ${screening.unscreened} 件・Jev）` }}
        </button>
    </div>
    <!-- 成否は絵文字ではなく色と文字で出す -->
    <p v-if="result" class="mt-2 border-l-[3px] px-[9px] py-1 text-[12px] leading-[19px]"
        :class="result.ok ? 'border-match bg-match-bg text-match-fg' : 'border-conflict bg-conflict-bg text-conflict-fg'">
        {{ result.message }}
    </p>
</template>
