<script setup lang="ts">
import HelpTip from '@/components/HelpTip.vue';
import KarteSkipped from '@/components/karte/KarteSkipped.vue';
import QuestionsGenerateButton from '@/components/karte/QuestionsGenerateButton.vue';
import { useClipboardCopy } from '@/composables/useClipboardCopy';
import { useCompanyTabNav } from '@/composables/useCompanyTab';
import { useInterviewTransfer } from '@/composables/useInterviewTransfer';
import { usePrintWithDetails } from '@/composables/usePrintWithDetails';
import { ASK_TO_LABEL, PRIORITY_LABEL, questionsToText } from '@/composables/useQuestionsClipboard';
import type { P6Questions, Priority, QuestionsState } from '@/types/karte';
import { HELP } from '@/constants/help';

const props = defineProps<{
    p6: P6Questions | null;
    skippedReason: string | null;
    companyId: number;
    state: QuestionsState;
}>();

// 優先度チップは幅48pxの1文字。全文（必ず聞く 等）は title だけに置かない
// （title はキーボード・タッチ・読み上げから読めず、この節にある「印刷」でも紙に出ない）。
// 画面ではチップ、読み上げと印刷では全文が出るようにする。
// コピー・印刷の本文は useQuestionsClipboard の PRIORITY_LABEL のまま（テキスト出力は変えない）
const PRIORITY_MARK: Record<Priority, string> = { high: '高', mid: '中', low: '低' };

const priorityCls: Record<Priority, string> = {
    high: 'border-conflict-border bg-conflict-bg text-conflict',
    mid: 'border-check/40 bg-check-bg text-check',
    low: 'border-rule-input bg-surface text-ink-3',
};

const { copiedKey, copyError, copy } = useClipboardCopy();
const copyAll = () => {
    if (props.p6) copy(questionsToText(props.p6.questions), 'all');
};

// 面接記録への転記（ソースタブ「面接記録を追加」の「聞いたこと」欄に入る）。
// どの質問に回答を得たかの転記を記憶頼みにしないための導線
const { staged, stage } = useInterviewTransfer();
const transferAll = () => {
    if (props.p6) stage(props.p6.questions.map((q) => q.question));
};
// 転記先のフォーム（ソースタブの下部）まで送る
const { setTab } = useCompanyTabNav();

// 印刷は折りたたみを開いてから（危険サイン・検証不能を出力から落とさない）
const { print } = usePrintWithDetails();
</script>

<template>
    <!-- スキップされたランでも、材料を足した後にここから再生成できるようボタンを残す（袋小路にしない） -->
    <template v-if="skippedReason">
        <KarteSkipped id="karte-questions" title="面接での逆質問" :reason="skippedReason" />
        <div class="mt-3 print:hidden">
            <QuestionsGenerateButton :company-id="companyId" :state="state" label="逆質問を生成する" />
        </div>
    </template>
    <section v-else-if="p6" id="karte-questions" class="scroll-mt-24">
        <div class="flex flex-wrap items-baseline justify-between gap-3 border-b-2 border-ink pb-[5px]">
            <h2 class="flex items-center gap-[6px] font-serif text-[19px] font-semibold text-ink">
                面接での逆質問（優先度順）
                <HelpTip :help="HELP.questions" />
            </h2>
            <div class="flex flex-wrap gap-2 print:hidden">
                <button type="button" @click="copyAll"
                    class="rounded-[2px] border border-rule-input bg-surface btn-sm text-ink hover:bg-bench">
                    {{ copiedKey === 'all' ? 'コピーしました' : '全部コピー' }}
                </button>
                <button type="button" @click="transferAll"
                    class="rounded-[2px] border border-rule-input bg-surface btn-sm text-ink hover:bg-bench">
                    全部を面接記録へ
                </button>
                <button type="button" @click="print"
                    class="rounded-[2px] border border-rule-input bg-surface btn-sm text-ink hover:bg-bench">
                    印刷
                </button>
                <QuestionsGenerateButton :company-id="companyId" :state="state" label="再生成" />
            </div>
        </div>
        <p v-if="copyError"
            class="mt-2 border-l-[3px] border-conflict bg-conflict-bg px-[11px] py-[7px] text-[12px] leading-[19px] text-conflict-fg print:hidden">
            コピーに失敗しました（ブラウザがクリップボードへの書き込みを拒否）。質問文を選択して手動でコピーしてください。
        </p>
        <p v-if="staged.length"
            class="mt-[10px] border-l-[3px] border-check bg-check-bg px-[11px] py-[7px] text-[12px] leading-[19px] text-check-fg print:hidden">
            {{ staged.length }}件を面接記録の「聞いたこと」欄に転記しました。
            <button type="button" class="font-semibold underline underline-offset-[3px] hover:text-conflict"
                @click="setTab('sources', 'interview-record')">記録を開く</button>
        </p>
        <ul class="mt-[14px] list-none p-0">
            <li v-for="(q, i) in p6.questions" :key="i" class="border-b border-rule-thin py-[14px] last:border-0">
                <div class="flex items-start gap-3">
                    <span class="w-12 shrink-0 rounded-[2px] border py-[2px] text-center text-[11px] font-semibold print:w-auto print:px-1"
                        :class="priorityCls[q.priority]" :title="PRIORITY_LABEL[q.priority] ?? q.priority">
                        <span aria-hidden="true" class="print:hidden">{{ PRIORITY_MARK[q.priority] ?? q.priority }}</span>
                        <span class="sr-only print:not-sr-only">{{ PRIORITY_LABEL[q.priority] ?? q.priority }}</span>
                    </span>
                    <span class="flex-1 font-serif text-[15px] leading-[26px] text-ink">{{ q.question }}</span>
                    <span class="flex shrink-0 gap-[10px] text-[11px] print:hidden">
                        <button type="button" @click="copy(q.question, String(i))" title="この質問をコピー"
                            class="text-ink-3 hover:text-ink">
                            {{ copiedKey === String(i) ? 'コピー済' : 'コピー' }}
                        </button>
                        <button type="button" @click="stage([q.question])" title="この質問を面接記録に転記"
                            class="text-ink-3 hover:text-ink">
                            {{ staged.includes(q.question) ? '転記済' : '面接記録へ' }}
                        </button>
                    </span>
                </div>
                <div class="mt-[6px] pl-[60px] text-[12px] leading-[19px] text-ink-2">
                    確認: {{ q.targets }} / 相手: {{ ASK_TO_LABEL[q.ask_to] ?? q.ask_to }}
                </div>
                <div v-if="q.casual_version" class="mt-[3px] pl-[60px] text-[12px] leading-[19px] text-ink-3">
                    カジュアル版: {{ q.casual_version }}
                </div>
            </li>
        </ul>
    </section>
    <!-- 逆質問は分析に含めない。面接に進まない企業で AI 実行を無駄に消費しないため -->
    <section v-else id="karte-questions" class="scroll-mt-24 border border-dashed border-ink-5 bg-surface px-4 py-[14px]">
        <h2 class="flex items-center gap-[6px] font-serif text-[15px] font-semibold text-ink">
            面接での逆質問
            <HelpTip :help="HELP.questions" />
        </h2>
        <p class="mt-1 mb-[10px] text-[13px] leading-[21px] text-ink-2">
            分析には含めていません。面接が決まってからボタンで生成してください（生成には AI の実行が1回かかります）。
            <br>
            矛盾・検証不能の主張が、面接でそのまま聞ける質問に変換されます。
        </p>
        <QuestionsGenerateButton :company-id="companyId" :state="state" label="逆質問を生成する" />
    </section>
</template>
