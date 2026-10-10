<script setup lang="ts">
import { useProfileForm } from '@/composables/useProfileForm';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import type { ProfileData } from '@/types/company';
import { useId } from 'vue';

const props = defineProps<{ profile: ProfileData | null }>();

const uid = useId();
const fid = (key: string) => `${uid}-${key}`;

const { form, submit } = useProfileForm(props.profile);

useUnsavedGuard(() => form.isDirty);

/**
 * 保存を拒否された理由。画面の欄名（mustText）と送る名前（must_json）が違ううえ、
 * 配列の要素は must_json.0 のように添字付きで返るため、前方一致で拾う。
 */
const errorFor = (field: string): string | undefined => {
    const errors = form.errors as Record<string, string | undefined>;
    const key = Object.keys(errors).find((k) => k === field || k.startsWith(`${field}.`));

    return key === undefined ? undefined : errors[key];
};

/** 1行に1つ入力する条件欄。ラベルと初期値の対応と、ラベル左の色分け（加点/減点）だけを持つ */
const CONDITIONS = [
    {
        key: 'must',
        field: 'must_json',
        label: '必須条件（1行に1つ・欠けたらNG）',
        model: 'mustText',
        mark: 'border-conflict',
        placeholder: '年収600万以上\nリモート週2日以上',
    },
    {
        key: 'want',
        field: 'want_json',
        label: '歓迎条件（多いほど加点）',
        model: 'wantText',
        mark: 'border-match',
        placeholder: 'TypeScript\nフレックス勤務',
    },
    {
        key: 'ng',
        field: 'ng_json',
        label: 'NG条件（あったら減点）',
        model: 'ngText',
        mark: 'border-check',
        placeholder: '固定残業40h以上\n転勤あり',
    },
] as const;
</script>

<template>
    <section>
        <h2 class="border-b-2 border-ink pb-[5px] font-serif text-[19px] font-semibold text-ink">プロファイル設定</h2>
        <p class="mt-2.5 text-[12px] leading-5 text-ink-2">
            カルテのおすすめ度はこの内容を基準に評価されます。条件は1行に1つ入力してください。
        </p>

        <!-- 条件欄は全幅1列のまま。横に3つ並べると rows="3" の3行目が隠れる -->
        <form class="mt-4 space-y-4" @submit.prevent="submit">
            <div>
                <label :for="fid('career-summary')" class="mb-[5px] block text-[12px] font-semibold text-ink">
                    職務経歴サマリ
                </label>
                <textarea :id="fid('career-summary')" v-model="form.career_summary" rows="3"
                    class="w-full text-[13px] leading-[22px]"
                    placeholder="例: Webエンジニア3年（PHP・API開発）" />
                <p v-if="errorFor('career_summary')" class="mt-1 text-[11px] text-conflict">{{ errorFor('career_summary') }}</p>
            </div>

            <div v-for="c in CONDITIONS" :key="c.key">
                <label :for="fid(c.key)" class="mb-[5px] block border-l-2 pl-[7px] text-[12px] font-semibold text-ink"
                    :class="c.mark">{{ c.label }}</label>
                <textarea :id="fid(c.key)" v-model="form[c.model]" rows="3" class="w-full text-[13px] leading-[22px]"
                    :placeholder="c.placeholder" />
                <p v-if="errorFor(c.field)" class="mt-1 text-[11px] text-conflict">{{ errorFor(c.field) }}</p>
            </div>

            <div>
                <label :for="fid('axis')" class="mb-[5px] block text-[12px] font-semibold text-ink">転職の軸</label>
                <textarea :id="fid('axis')" v-model="form.axis" rows="2" class="w-full text-[13px] leading-[22px]"
                    placeholder="なぜ転職するか・何を変えたいか" />
                <p v-if="errorFor('axis')" class="mt-1 text-[11px] text-conflict">{{ errorFor('axis') }}</p>
            </div>

            <div>
                <label :for="fid('current-salary')" class="mb-[5px] block text-[12px] font-semibold text-ink">
                    現在の年収（万円・オファー比較の基準）
                </label>
                <input :id="fid('current-salary')" v-model.number="form.current_annual_salary" type="number" min="0"
                    class="w-full text-[13px] tabular-nums sm:w-44" placeholder="500" />
                <p v-if="errorFor('current_annual_salary')" class="mt-1 text-[11px] text-conflict">
                    {{ errorFor('current_annual_salary') }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" :disabled="form.processing"
                    class="rounded-[2px] bg-ink btn-md font-medium text-paper disabled:opacity-50">
                    {{ form.processing ? '保存中…' : '保存' }}
                </button>
                <span v-if="form.recentlySuccessful" class="text-[12px] text-match-fg">保存しました</span>
            </div>
        </form>
    </section>
</template>
