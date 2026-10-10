<script setup lang="ts">
import AiDecisionApiKeyRow from '@/components/settings/AiDecisionApiKeyRow.vue';
import AiDecisionInterviewNotesField from '@/components/settings/AiDecisionInterviewNotesField.vue';
import AiDecisionModelPicker from '@/components/settings/AiDecisionModelPicker.vue';
import AiDecisionOrderingField from '@/components/settings/AiDecisionOrderingField.vue';
import type { AiDecisionForm, AiDecisionProviderStatus, AiDecisionStatus, AiDecisionTestResult } from '@/types/ai';
import { useForm } from '@inertiajs/vue3';
import { computed, useId } from 'vue';

const props = defineProps<{ decision: AiDecisionStatus; testResult: AiDecisionTestResult | null }>();

const uid = useId();
const fid = (key: string) => `${uid}-${key}`;

const find = (provider: string | null): AiDecisionProviderStatus | undefined =>
    props.decision.providers.find((p) => p.provider === provider);

// オフ（provider 空）でもモデルは残っているので、判定専用が1つだけならその設定を出す
const initial = find(props.decision.provider) ?? (props.decision.providers.length === 1 ? props.decision.providers[0] : undefined);

const form = useForm<AiDecisionForm>({
    provider: props.decision.provider ?? '',
    model: initial?.modelDecision ?? '',
    review_ordering: props.decision.reviewOrdering,
    send_interview_notes: props.decision.sendInterviewNotes,
});

const target = computed(() => find(form.provider) ?? initial);

// テストは保存済みの設定と .env のキーで走る。保存済みの実行先にキーがあるときだけ出す
const canTest = computed(() => find(props.decision.provider)?.hasApiKey === true);
const testForm = useForm({});

// 保存も接続テストも、このページで変わるのは ai と flash だけ
const save = () => form.put('/settings/ai/decision', { preserveScroll: true, only: ['ai', 'flash'] });
const runTest = () => testForm.post('/settings/ai/decision/test', { preserveScroll: true, only: ['ai', 'flash'] });
</script>

<template>
    <form @submit.prevent="save">
        <div class="grid gap-x-4 gap-y-3 text-[13px] sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-baseline">
            <label :for="fid('provider')" class="font-semibold text-ink">実行先</label>
            <div>
                <select :id="fid('provider')" v-model="form.provider" class="w-full text-[13px]">
                    <option value="">なし（オフ）</option>
                    <option v-for="p in decision.providers" :key="p.provider" :value="p.provider"
                        :disabled="!decision.available.includes(p.provider)">
                        {{ p.label }}{{ decision.available.includes(p.provider) ? '' : '（キー未設定）' }}
                    </option>
                </select>
                <p v-if="form.errors.provider" class="mt-1 text-[11px] text-conflict">{{ form.errors.provider }}</p>
            </div>

            <AiDecisionApiKeyRow :target="target" />

            <label :for="fid('model')" class="font-semibold text-ink">判定用モデル</label>
            <!-- オフで保存してもモデルは書き換わらない（保存済みを残す）ので、入力を止めて黙って捨てない -->
            <fieldset :disabled="form.provider === ''" class="min-w-0">
                <AiDecisionModelPicker v-model="form.model" :id="fid('model')" :models="target?.models ?? []"
                    :placeholder="target?.modelPlaceholder ?? ''" />
                <p v-if="form.errors.model" class="mt-1 text-[11px] text-conflict">{{ form.errors.model }}</p>
                <p v-if="form.provider === ''" class="mt-1 text-[11px] text-ink-3">オフの間はモデルを保存しません（保存済みの名前は残ります）</p>
                <p class="mt-[5px] text-[11px] leading-[17px] text-ink-3">
                    バージョン固定を推奨。jev-latest は予告なく中身が変わり、しきい値の意味がずれます<br />
                    費用は入力トークンのみ課金（出力無料）。実額は使用状況ページに provider=jev で載ります
                </p>
            </fieldset>

            <AiDecisionOrderingField v-model="form.review_ordering" :id="fid('ordering')" :disabled="form.provider === ''"
                :error="form.errors.review_ordering" />
            <AiDecisionInterviewNotesField v-model="form.send_interview_notes" :id="fid('interview-notes')"
                :disabled="form.provider === ''" :error="form.errors.send_interview_notes" />
        </div>

        <p v-for="(note, i) in target?.notes ?? []" :key="i"
            class="mt-3 border-l-[3px] border-rule-input pl-[11px] text-[11px] leading-[18px] text-ink-2">
            {{ note }}
        </p>

        <div class="mt-3 flex flex-wrap items-center gap-2.5">
            <button type="submit" :disabled="form.processing"
                class="rounded-[2px] bg-ink btn-md font-medium text-paper disabled:opacity-50">
                {{ form.processing ? '保存中…' : '保存' }}
            </button>
            <button v-if="canTest" type="button" :disabled="testForm.processing" @click="runTest"
                class="rounded-[2px] border border-rule-input bg-white btn-md text-ink hover:bg-bench disabled:opacity-50">
                {{ testForm.processing ? '確認中…' : '接続をテスト（保存済みの設定・Noul 1 問）' }}
            </button>
            <span v-if="form.recentlySuccessful" class="text-[12px] text-match">保存しました</span>
            <a v-if="target" :href="target.docsUrl" target="_blank" rel="noreferrer"
                class="text-[12px] text-ink underline underline-offset-[3px] hover:text-conflict">モデル名・キーの発行元</a>
        </div>

        <!-- 成否は絵文字ではなく色と文字で出す -->
        <p v-if="testResult" class="mt-2 border-l-[3px] px-[9px] py-1 text-[12px] leading-[19px]"
            :class="testResult.ok ? 'border-match bg-match-bg text-match-fg' : 'border-conflict bg-conflict-bg text-conflict-fg'">
            {{ testResult.message }}
        </p>
    </form>
</template>
