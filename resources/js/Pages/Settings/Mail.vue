<script setup lang="ts">
import GmailSection from '@/components/settings/GmailSection.vue';
import type { SettingsNavValues } from '@/constants/settings';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import type { GmailStatus, GmailTestResult, MailFetchResult } from '@/types/mail';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    gmail: GmailStatus;
    /** 接続テスト・手動取り込みの結果。押したときだけ flash で届く */
    flash: { gmailTest: GmailTestResult | null; mailFetch: MailFetchResult | null };
}>();

// この画面に届く props で分かるのはメール取り込みの節だけ。他の節の現在値は目次に出さない
const navValues = computed<SettingsNavValues>(() => {
    const g = props.gmail;

    if (g.connected) {
        // 連携していてもドメインが空なら1通も取り込まない。目次でもその差を書き分ける
        return {
            '/settings/mail': g.allowedDomains.length
                ? { text: g.autoFetch ? '連携中・自動取得' : '連携中・手動取得', tone: 'match' }
                : { text: '連携中・ドメイン未設定', tone: 'conflict' },
        };
    }

    return {
        '/settings/mail': g.configured
            ? { text: 'クライアント登録済み・未連携', tone: 'check' }
            : { text: '未設定', tone: 'check' },
    };
});
</script>

<template>
    <Head title="メール取り込み設定" />
    <SettingsLayout :nav-values="navValues">
        <GmailSection :gmail="gmail" :test-result="flash.gmailTest" :fetch-result="flash.mailFetch" />
    </SettingsLayout>
</template>
