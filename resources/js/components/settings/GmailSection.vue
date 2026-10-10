<script setup lang="ts">
import HelpTip from '@/components/HelpTip.vue';
import GmailSettingForm from '@/components/settings/GmailSettingForm.vue';
import GmailSetupSteps from '@/components/settings/GmailSetupSteps.vue';
import MailFetchModeField from '@/components/settings/MailFetchModeField.vue';
import { HELP } from '@/constants/help';
import type { GmailStatus, GmailTestResult, MailFetchResult } from '@/types/mail';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

// 連携状態と「繋がっていない理由」は PHP(BuildGmailStatusAction) 側で確定済み
const props = defineProps<{
    gmail: GmailStatus;
    testResult: GmailTestResult | null;
    fetchResult: MailFetchResult | null;
}>();

// 押した結果は接続テストか手動取り込みのどちらか一方しか届かないので、出し先は1つでよい
const result = computed(() => props.fetchResult ?? props.testResult);

const testForm = useForm({});
const runTest = () => testForm.post('/settings/mail/test', { preserveScroll: true });

const fetchForm = useForm({});
const runFetch = () => fetchForm.post('/settings/mail/fetch', { preserveScroll: true });

const disconnect = () => {
    if (!confirm('Gmail連携を解除します。以降の自動取り込みは止まります。よろしいですか？')) return;
    router.delete('/auth/google', { preserveScroll: true });
};
</script>

<template>
    <section>
        <div class="flex flex-wrap items-baseline justify-between gap-3 border-b-2 border-ink pb-[5px]">
            <h2 class="flex items-center gap-1.5 font-serif text-[19px] font-semibold text-ink">
                Gmail連携（メール取り込み）
                <HelpTip :help="HELP.gmailSetup" />
            </h2>
            <Link href="/mails" class="text-[12px] text-ink-2 underline underline-offset-[3px] hover:text-conflict">
                取り込み履歴を見る
            </Link>
        </div>

        <div v-if="gmail.connected" class="mt-3 text-[13px]">
            <p class="flex flex-wrap items-baseline gap-2.5">
                <span class="border-l-2 border-match pl-[7px] text-[12px] text-match-fg">連携中</span>
                <span class="text-ink">{{ gmail.email }}</span>
            </p>
            <p class="mt-1.5 text-[12px] leading-5 text-ink-2">
                最終取得: {{ gmail.lastFetchedAt ?? 'まだ取得していません' }} /
                1回につき直近{{ gmail.windowDays }}日・最大{{ gmail.maxMessages }}通
            </p>
            <!-- 直近の取得失敗。自動取得が黙って止まっていることに気づけるようにする -->
            <p v-if="gmail.lastFetchError" role="alert" class="mt-1 text-[12px] leading-5 text-conflict">
                直近の取得が失敗しました<template v-if="gmail.lastFetchErrorAt">（{{ gmail.lastFetchErrorAt }}）</template>: {{ gmail.lastFetchError }}
            </p>
            <p v-if="!gmail.allowedDomains.length" class="mt-1 text-[12px] leading-5 text-conflict">
                取り込む差出人ドメインが未設定です。このままでは1通も取り込みません
            </p>
            <p v-else class="mt-1 text-[12px] leading-5 text-ink-2">対象: {{ gmail.allowedDomains.join(' / ') }}</p>
        </div>
        <p v-else-if="gmail.configured" class="mt-3 text-[13px] leading-[21px] text-ink-2">
            クライアントは登録済みです。下のボタンで Google にログインすると取り込みが始まります。
        </p>

        <!-- 連携前でも出す。「自動で取りに行かれるのか」は連携を決める前に知りたい情報のため -->
        <MailFetchModeField :auto-fetch="gmail.autoFetch" :connected="gmail.connected" />

        <div class="mt-3 flex flex-wrap items-center gap-2">
            <button v-if="gmail.connected" type="button" :disabled="fetchForm.processing"
                class="rounded-[2px] bg-ink btn-sm font-medium text-paper disabled:opacity-50"
                @click="runFetch">{{ fetchForm.processing ? '取り込み中…' : 'いま取り込む' }}</button>
            <a v-if="gmail.configured && !gmail.connected" href="/auth/google/redirect"
                class="rounded-[2px] bg-ink btn-sm font-medium text-paper">Gmailと連携する</a>
            <button v-if="gmail.configured" type="button" :disabled="testForm.processing"
                class="rounded-[2px] border border-rule-input bg-white btn-sm text-ink hover:bg-bench disabled:opacity-50"
                @click="runTest">{{ testForm.processing ? '確認中…' : '接続をテスト' }}</button>
            <button v-if="gmail.connected" type="button"
                class="rounded-[2px] border border-conflict-border bg-white btn-sm text-conflict hover:bg-conflict-bg"
                @click="disconnect">連携を解除する</button>
        </div>

        <p v-if="result" class="mt-3 border-l-[3px] px-[9px] py-1 text-[12px] leading-5"
            :class="result.ok ? 'border-match bg-match-bg text-match-fg' : 'border-conflict bg-conflict-bg text-conflict-fg'">
            {{ result.message }}
        </p>

        <details class="mt-3.5 border-t border-rule pt-3" :open="!gmail.configured">
            <summary class="cursor-pointer text-[12px] text-ink-2 hover:text-ink">
                {{ gmail.configured ? '接続設定を編集する' : 'セットアップ手順を開く（初回のみ・10分ほど）' }}
            </summary>
            <div class="mt-3.5">
                <GmailSetupSteps :gmail="gmail" />
                <div class="mt-4 border-t border-rule pt-3.5">
                    <GmailSettingForm :gmail="gmail" />
                </div>
            </div>
        </details>
    </section>
</template>
