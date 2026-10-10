<script setup lang="ts">
import HelpTip from '@/components/HelpTip.vue';
import AiDecisionSection from '@/components/settings/AiDecisionSection.vue';
import AiProviderForm from '@/components/settings/AiProviderForm.vue';
import AiRoutingForm from '@/components/settings/AiRoutingForm.vue';
import { HELP } from '@/constants/help';
import type { AiDecisionTestResult, AiProviderKey, AiProviderStatus, AiStatus, AiTestResult, AiTierKey } from '@/types/ai';
import { ref, useId } from 'vue';

const props = defineProps<{
    ai: AiStatus;
    testResult: AiTestResult | null;
    decisionTestResult: AiDecisionTestResult | null;
    mailAutoFetch: boolean;
}>();

// 結果はどのプロバイダのテストかを添えて届く。押した行にだけ出す
const resultFor = (provider: AiProviderStatus): AiTestResult | null =>
    props.testResult?.provider === provider.provider ? props.testResult : null;

const uid = useId();
const panelId = (provider: AiProviderKey) => `${uid}-${provider}`;

// 開いているプロバイダは1つ。折りたたみの中身が見えないまま並ぶのをやめ、一覧で状態を見せる。
// 接続テストの結果が届いたプロバイダは、押した設定が見えるように開いた状態から始める
const open = ref<AiProviderKey | null>(props.testResult?.provider ?? null);
const toggle = (provider: AiProviderKey) => {
    open.value = open.value === provider ? null : provider;
};

const TIER_KEYS: AiTierKey[] = ['analysis', 'light'];

/** 推論オプションの設定状況。中身（Tier × 項目）を開かずに読めるようにする */
const optionSummary = (p: AiProviderStatus): string => {
    const total = p.fields.length * TIER_KEYS.length;

    if (total === 0) return 'なし';

    // 0 は「既定に任せる」ではなく指定した値なので、空文字だけを未設定として数える
    const filled = p.fields.reduce(
        (count, field) => count + TIER_KEYS.filter((tier) => `${p.options[tier][field.key] ?? ''}` !== '').length,
        0,
    );

    if (filled === 0) return `${total}件すべて未設定`;

    return `${filled}件設定 / ${total - filled}件未設定`;
};
</script>

<template>
    <section>
        <h2
            class="flex items-center gap-1.5 border-b-2 border-ink pb-[5px] font-serif text-[19px] font-semibold text-ink">
            AIエンジン
            <HelpTip :help="HELP.aiEngine" />
        </h2>

        <div class="mt-3.5">
            <AiRoutingForm :status="ai" :mail-auto-fetch="mailAutoFetch" />
        </div>

        <h3 class="mt-6 border-b border-ink pb-1 font-serif text-[15px] font-semibold text-ink">プロバイダの接続設定</h3>
        <p class="mt-2 text-[12px] leading-5 text-ink-2">
            プロバイダごとの接続設定。ドライバ実装済みのプロバイダにキーを登録すると、上の実行先の候補に出ます
        </p>

        <table class="mt-3 w-full text-[13px]">
            <thead>
                <tr class="border-b border-ink text-left text-[11px] font-medium tracking-[0.08em] text-ink-3">
                    <th scope="col" class="pr-3 pb-1.5 font-medium">プロバイダ</th>
                    <th scope="col" class="pr-3 pb-1.5 font-medium">APIキー</th>
                    <th scope="col" class="pr-3 pb-1.5 font-medium">モデル（分析・要約）</th>
                    <th scope="col" class="pr-3 pb-1.5 font-medium">モデル（抽出・判定）</th>
                    <th scope="col" class="pb-1.5 text-right font-medium">推論オプション</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in ai.providers" :key="p.provider" class="border-b border-rule-thin align-top"
                    :class="open === p.provider ? 'bg-surface' : ''">
                    <td class="py-[9px] pr-3">
                        <span class="font-medium text-ink">{{ p.label }}</span>
                        <!-- キーは登録できるが実行先には選べない。設定できるのに動かない状態を隠さない -->
                        <span v-if="!p.driverReady"
                            class="ml-1.5 border-l-2 border-check bg-check-bg px-[7px] py-px text-[11px] text-check-fg">
                            ドライバ未実装
                        </span>
                    </td>
                    <td class="py-[9px] pr-3 whitespace-nowrap">
                        <span v-if="p.isSubprocess"
                            class="border-l-2 border-rule-input bg-canvas px-[7px] py-px text-[11px] text-ink-3">キー不要</span>
                        <span v-else-if="p.hasApiKey"
                            class="border-l-2 border-match bg-match-bg px-[7px] py-px text-[11px] text-match-fg">設定済み</span>
                        <span v-else
                            class="border-l-2 border-check bg-check-bg px-[7px] py-px text-[11px] text-check-fg">未設定</span>
                        <code v-if="p.apiKeyEnvName" class="ml-1.5 font-mono text-[11px] text-ink-4">{{
                            p.apiKeyEnvName
                        }}</code>
                    </td>
                    <td class="py-[9px] pr-3" :class="p.modelAnalysis ? 'text-ink' : 'text-ink-4'">
                        {{ p.modelAnalysis || '未設定' }}
                    </td>
                    <td class="py-[9px] pr-3" :class="p.modelLight ? 'text-ink' : 'text-ink-4'">
                        {{ p.modelLight || '未設定' }}
                    </td>
                    <td class="py-[9px] text-right text-[11px] text-ink-3">
                        {{ optionSummary(p) }}
                        <button type="button" :aria-expanded="open === p.provider" :aria-controls="panelId(p.provider)"
                            @click="toggle(p.provider)"
                            class="ml-2 text-[12px] text-ink underline underline-offset-[3px] hover:text-conflict">
                            {{ open === p.provider ? '閉じる' : '設定を開く' }}
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- v-show で残すのは、閉じても入力中のモデル名・オプションを捨てないため -->
        <div v-for="p in ai.providers" :key="p.provider" v-show="open === p.provider" :id="panelId(p.provider)"
            class="mt-3.5 border border-rule-input bg-surface p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h4 class="font-serif text-[15px] font-semibold text-ink">{{ p.label }} の設定</h4>
                <button type="button" @click="open = null"
                    class="text-[12px] text-ink-2 underline underline-offset-[3px] hover:text-conflict">閉じる</button>
            </div>
            <div class="mt-2">
                <AiProviderForm :provider="p" :test-result="resultFor(p)" />
            </div>
        </div>

        <AiDecisionSection :decision="ai.decision" :test-result="decisionTestResult" />
    </section>
</template>
