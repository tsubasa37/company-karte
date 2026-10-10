<script setup lang="ts">
import type { AiProviderStatus, AiStatus, AiTierField } from '@/types/ai';
import { useForm } from '@inertiajs/vue3';
import { computed, useId } from 'vue';

const props = defineProps<{ status: AiStatus; mailAutoFetch: boolean }>();

const uid = useId();
const fid = (key: string) => `${uid}-${key}`;

const TIERS: AiTierField[] = [
    {
        key: 'analysis',
        name: 'analysis_provider',
        label: '分析・要約',
        hint: '分析（P1〜P5）・逆質問・面接前ブリーフィング。ボタンを押したときだけ走ります',
    },
    {
        key: 'light',
        name: 'light_provider',
        label: '抽出・判定',
        hint: 'クリップの企業/種別判定・口コミの分割・労働条件の抽出・メールの抽出',
    },
];

const form = useForm({
    analysis_provider: props.status.routing.analysis,
    light_provider: props.status.routing.light,
});

// 候補はドライバ実装済み かつ（codex または キー登録済み）。ラベルはプロバイダ行から引く
const choices = computed(() => props.status.providers.filter((p) => props.status.available.includes(p.provider)));

// 経路の右端は「いま選んでいる実行先」の設定を出す。保存前でも用途→実行先→モデル→課金が1行で読める
// （未変更なら status.current と同じ値。どちらも ai_provider_configs の model_analysis / model_light）
const target = (tier: AiTierField): AiProviderStatus | undefined =>
    props.status.providers.find((p) => p.provider === form[tier.name]);

/** モデル未設定は実行時に例外で止まる。空欄を隠さずそのまま出す */
const modelLabel = (tier: AiTierField): string => {
    const config = target(tier);
    const model = tier.key === 'analysis' ? config?.modelAnalysis : config?.modelLight;

    return model === null || model === undefined || model === '' ? 'モデル未設定' : model;
};

/** 課金の出どころ。サブスク実行は枠を消費し、API は使った分だけ課金される */
const billing = (tier: AiTierField): { label: string; cls: string } =>
    target(tier)?.isSubprocess
        ? { label: 'サブスク', cls: 'border-match bg-match-bg text-match-fg' }
        : { label: '従量課金', cls: 'border-check bg-check-bg text-check-fg' };

// 実行先の保存で変わるのは ai だけ。flash は前回の接続テスト結果を消すために含める
const save = () => form.put('/settings/ai/routing', { preserveScroll: true, only: ['ai', 'flash'] });
</script>

<template>
    <form class="text-sm" @submit.prevent="save">
        <div class="flex flex-col gap-2.5">
            <!-- 抽出・判定は押していなくても走る。その1行だけ枠を変え、警告を行の中に入れる -->
            <div v-for="tier in TIERS" :key="tier.key" class="border bg-surface px-[15px] py-[13px]"
                :class="tier.key === 'light' ? 'border-[#E5CF9E]' : 'border-rule-input'">
                <div class="grid gap-2.5 sm:grid-cols-[9rem_1.1rem_12rem_1.1rem_minmax(0,1fr)] sm:items-center">
                    <label :for="fid(tier.key)" class="text-[13px] font-semibold text-ink">{{ tier.label }}</label>
                    <span aria-hidden="true" class="hidden text-center text-[13px] text-ink-4 sm:block">→</span>
                    <select :id="fid(tier.key)" v-model="form[tier.name]" class="w-full text-[13px]">
                        <option v-for="p in choices" :key="p.provider" :value="p.provider">{{ p.label }}</option>
                    </select>
                    <span aria-hidden="true" class="hidden text-center text-[13px] text-ink-4 sm:block">→</span>
                    <div class="flex flex-wrap items-baseline gap-2">
                        <code class="bg-canvas px-[7px] py-[2px] font-mono text-[12px] text-ink">{{
                            modelLabel(tier)
                        }}</code>
                        <span class="border-l-2 px-[7px] py-px text-[11px]" :class="billing(tier).cls">{{
                            billing(tier).label
                        }}</span>
                    </div>
                </div>

                <p class="mt-2 text-[11px] leading-[17px] text-ink-3">{{ tier.hint }}</p>

                <!-- 「押していないのに課金される」のは抽出・判定だけなので、選ぶ前に出す。
                     メール取得は自動を選んでいるときだけ走るので、走らない状態では書かない -->
                <p v-if="tier.key === 'light'"
                    class="mt-2 border-l-[3px] border-check bg-check-bg px-[11px] py-[7px] text-[12px] leading-[19px] text-check-fg">
                    抽出・判定は、ソースの保存<template v-if="mailAutoFetch">と15分おきのメール取得</template>に付随して自動実行されます。ここでAPIを選ぶと、ボタンを押していなくても課金されます。
                    <template v-if="!mailAutoFetch">
                        メールの自動取得は現在オフなので、この経路では走りません。
                    </template>
                </p>

                <p v-if="form.errors[tier.name]" class="mt-2 text-[11px] text-conflict">{{ form.errors[tier.name] }}</p>
            </div>
        </div>

        <div class="mt-3 flex items-center gap-3">
            <button type="submit" :disabled="form.processing"
                class="rounded-[2px] bg-ink btn-md font-medium text-paper disabled:opacity-50">保存</button>
            <span v-if="form.recentlySuccessful" class="text-[12px] text-match">保存しました</span>
        </div>

        <!-- 上の行は選択中の値。保存済み（いま実際に走る実行先）は別に出す。選択を変えて未保存のときに差が分かる -->
        <p class="mt-3 text-[11px] leading-[17px] text-ink-3">
            いまの実行先（保存済み）:
            分析・要約 = {{ status.current.analysis.providerLabel }}（{{ status.current.analysis.model ?? 'モデル未設定' }}）
            / 抽出・判定 = {{ status.current.light.providerLabel }}（{{ status.current.light.model ?? 'モデル未設定' }}）
        </p>
    </form>
</template>
