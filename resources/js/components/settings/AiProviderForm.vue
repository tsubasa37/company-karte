<script setup lang="ts">
import AiModelPicker from '@/components/settings/AiModelPicker.vue';
import AiProviderField from '@/components/settings/AiProviderField.vue';
import type { AiProviderConfigForm, AiProviderStatus, AiProviderTierField, AiTestResult, AiTierKey } from '@/types/ai';
import { useForm } from '@inertiajs/vue3';

const props = defineProps<{ provider: AiProviderStatus; testResult: AiTestResult | null }>();

// 表の列。Tier ごとの入力は列に分け、項目名は行見出しに1回だけ出す（入力の数は Tier × 項目のまま）
const TIERS: AiProviderTierField[] = [
    { key: 'analysis', label: '分析・要約', model: 'model_analysis' },
    { key: 'light', label: '抽出・判定', model: 'model_light' },
];

// API キーはこのフォームで扱わない（置き場所は .env だけ）。
// 型を明示するのは、number 項目を v-model.number が数値で書き戻すため（無注釈だと string に推論される）
const form = useForm<AiProviderConfigForm>({
    model_analysis: props.provider.modelAnalysis ?? '',
    model_light: props.provider.modelLight ?? '',
    options: {
        analysis: { ...props.provider.options.analysis },
        light: { ...props.provider.options.light },
    },
});

const testForm = useForm({});

// 入れ子の項目のエラーは "options.analysis.reasoning_effort" のドット鍵で届く
const optionError = (tier: AiTierKey, key: string): string | undefined =>
    (form.errors as Record<string, string>)[`options.${tier}.${key}`];

// 保存も接続テストも、このページで変わるのは ai だけ
// （接続テストは数トークン消費するが、使用状況は /settings/usage で見る）
const save = () =>
    form.put(`/settings/ai/providers/${props.provider.provider}`, {
        preserveScroll: true,
        only: ['ai', 'flash'],
    });
const runTest = () =>
    testForm.post(`/settings/ai/providers/${props.provider.provider}/test`, {
        preserveScroll: true,
        only: ['ai', 'flash'],
    });
</script>

<template>
    <form @submit.prevent="save">
        <!-- キーは .env に書く。画面では設定済みかと変数名だけを示す（値は受け取らない） -->
        <p v-if="provider.apiKeyEnvName" class="text-[12px] leading-5 text-ink-2">
            APIキー:
            <span v-if="provider.hasApiKey"
                class="border-l-2 border-match bg-match-bg px-[7px] py-px text-[11px] text-match-fg">設定済み</span>
            <span v-else
                class="border-l-2 border-check bg-check-bg px-[7px] py-px text-[11px] text-check-fg">未設定</span>
            <code class="ml-1 bg-canvas px-[5px] font-mono text-[11px] text-ink">{{ provider.apiKeyEnvName }}</code>
            を .env に書きます（この画面では扱いません）
        </p>

        <table class="mt-3 w-full text-[13px]">
            <thead>
                <tr class="border-b border-ink text-left">
                    <th scope="col" class="w-[34%] pr-3 pb-1.5 text-[11px] font-medium tracking-[0.08em] text-ink-3">
                        設定項目
                    </th>
                    <th v-for="tier in TIERS" :key="tier.key" scope="col"
                        class="pr-3 pb-1.5 text-[12px] font-semibold text-ink last:pr-0">
                        {{ tier.label }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr class="border-b border-rule-thin align-top">
                    <th scope="row" class="py-[11px] pr-3 text-left text-[13px] font-medium text-ink">モデル</th>
                    <td v-for="tier in TIERS" :key="tier.key" class="py-[11px] pr-3 last:pr-0">
                        <AiModelPicker v-model="form[tier.model]" :label="`モデル（${tier.label}）`"
                            :models="provider.models" :placeholder="provider.modelPlaceholder"
                            :is-subprocess="provider.isSubprocess" :error="form.errors[tier.model]" />
                    </td>
                </tr>
                <tr v-for="field in provider.fields" :key="field.key" class="border-b border-rule-thin align-top">
                    <th scope="row" class="py-[11px] pr-3 text-left font-medium">
                        <span class="text-[13px] text-ink">{{ field.label }}</span>
                        <span v-if="field.help" class="mt-[3px] block text-[11px] leading-[17px] font-normal text-ink-3">
                            {{ field.help }}
                        </span>
                    </th>
                    <td v-for="tier in TIERS" :key="tier.key" class="py-[11px] pr-3 last:pr-0">
                        <AiProviderField v-model="form.options[tier.key][field.key]" :field="field"
                            :label="`${field.label}（${tier.label}）`" :error="optionError(tier.key, field.key)" />
                    </td>
                </tr>
            </tbody>
        </table>

        <p v-for="(note, i) in provider.notes" :key="i"
            class="mt-3 border-l-[3px] border-rule-input pl-[11px] text-[11px] leading-[18px] text-ink-2">
            {{ note }}
        </p>

        <div class="mt-3 flex flex-wrap items-center gap-2.5">
            <button type="submit" :disabled="form.processing"
                class="rounded-[2px] bg-ink btn-md font-medium text-paper disabled:opacity-50">
                {{ form.processing ? '保存中…' : '保存' }}
            </button>
            <!-- テストは保存済みのモデルと .env のキーで走る。キーが無いうちは試せないので出さない -->
            <button v-if="provider.isSubprocess || provider.hasApiKey" type="button" :disabled="testForm.processing"
                @click="runTest"
                class="rounded-[2px] border border-rule-input bg-white btn-md text-ink hover:bg-bench disabled:opacity-50">
                {{ testForm.processing ? '確認中…' : '接続をテスト（保存済みの設定・数トークン消費）' }}
            </button>
            <span v-if="form.recentlySuccessful" class="text-[12px] text-match">保存しました</span>
            <a :href="provider.docsUrl" target="_blank" rel="noreferrer"
                class="text-[12px] text-ink underline underline-offset-[3px] hover:text-conflict">モデル名・キーの発行元</a>
        </div>

        <!-- 成否は絵文字ではなく色と文字で出す -->
        <p v-if="testResult" class="mt-2 border-l-[3px] px-[9px] py-1 text-[12px] leading-[19px]"
            :class="testResult.ok ? 'border-match bg-match-bg text-match-fg' : 'border-conflict bg-conflict-bg text-conflict-fg'">
            {{ testResult.message }}
        </p>
    </form>
</template>
