<script setup lang="ts">
import type { QuestionsState } from '@/types/karte';
import { useForm } from '@inertiajs/vue3';

const props = defineProps<{ companyId: number; state: QuestionsState; label: string }>();

const form = useForm({});
const generate = () => form.post(`/companies/${props.companyId}/questions`, { preserveScroll: true });
</script>

<template>
    <div class="print:hidden">
        <button type="button" @click="generate" :disabled="state.isGenerating || form.processing"
            class="rounded-[2px] bg-ink btn-sm font-medium text-paper disabled:opacity-50 focus:outline-2 focus:-outline-offset-1 focus:outline-ink">
            {{ state.isGenerating ? '生成中…' : label }}
        </button>
        <p v-if="state.error"
            class="mt-2 border-l-[3px] border-conflict bg-conflict-bg px-[11px] py-[7px] text-[12px] leading-[19px] text-conflict-fg">
            生成に失敗しました: {{ state.error }}
        </p>
        <p v-if="form.errors.questions" class="mt-2 text-[12px] text-conflict">{{ form.errors.questions }}</p>
    </div>
</template>
