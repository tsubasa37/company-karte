<script setup lang="ts">
import type { Option } from '@/types/company';
import { useForm } from '@inertiajs/vue3';
import { useId } from 'vue';

const props = defineProps<{ companyId: number; metricSourceOptions: Option[] }>();

// label[for] と input[id] の紐付け用。同一画面に複数描画されても衝突しないよう useId で採番する
const uid = useId();
const fid = (key: string) => `${uid}-${key}`;

const form = useForm({ source: 'edinet', key: '', value: '', unit: '', fiscal_year: '', source_url: '' });
const add = () =>
    form.post(`/companies/${props.companyId}/metrics`, {
        preserveScroll: true,
        onSuccess: () => form.reset('key', 'value', 'unit', 'fiscal_year', 'source_url'),
    });
</script>

<template>
    <form class="space-y-[10px] border-t border-rule pt-[18px]" @submit.prevent="add">
        <div class="flex flex-wrap gap-4">
            <div>
                <label :for="fid('source')" class="mb-1 block text-[11px] text-ink-3">出典</label>
                <select :id="fid('source')" v-model="form.source" class="w-44 text-[13px] text-ink">
                    <option v-for="o in metricSourceOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
            </div>
            <div>
                <label :for="fid('key')" class="mb-1 block text-[11px] text-ink-3">項目</label>
                <input :id="fid('key')" v-model="form.key" type="text" placeholder="例: 平均年間給与"
                    class="w-52 text-[13px] text-ink" />
            </div>
            <div>
                <label :for="fid('value')" class="mb-1 block text-[11px] text-ink-3">値</label>
                <input :id="fid('value')" v-model="form.value" type="text" placeholder="例: 620"
                    class="w-32 font-mono text-[13px] tabular-nums text-ink" />
            </div>
            <div>
                <label :for="fid('unit')" class="mb-1 block text-[11px] text-ink-3">単位</label>
                <input :id="fid('unit')" v-model="form.unit" type="text" placeholder="例: 万円"
                    class="w-28 text-[13px] text-ink" />
            </div>
            <div>
                <label :for="fid('fiscal-year')" class="mb-1 block text-[11px] text-ink-3">年度</label>
                <input :id="fid('fiscal-year')" v-model="form.fiscal_year" type="text" placeholder="例: 2024"
                    class="w-28 font-mono text-[13px] tabular-nums text-ink" />
            </div>
            <div>
                <!-- 手入力ガイドの手順4が案内する欄。あとから根拠のページへ戻れるようにする -->
                <label :for="fid('source-url')" class="mb-1 block text-[11px] text-ink-3">出典URL（任意）</label>
                <input :id="fid('source-url')" v-model="form.source_url" type="url"
                    placeholder="例: https://shokuba.mhlw.go.jp/…" class="w-72 text-[13px] text-ink" />
            </div>
        </div>
        <p v-if="form.errors.key" class="text-[12px] text-conflict">{{ form.errors.key }}</p>
        <p v-if="form.errors.value" class="text-[12px] text-conflict">{{ form.errors.value }}</p>
        <p v-if="form.errors.source_url" class="text-[12px] text-conflict">{{ form.errors.source_url }}</p>
        <button type="submit" :disabled="form.processing"
            class="rounded-[2px] bg-ink btn-md font-medium text-paper disabled:opacity-50">
            公式数値を追加
        </button>
    </form>
</template>
