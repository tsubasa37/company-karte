<script setup lang="ts">
import AiSection from '@/components/settings/AiSection.vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import type { AiDecisionTestResult, AiStatus, AiTestResult } from '@/types/ai';
import { Head } from '@inertiajs/vue3';

defineProps<{
    ai: AiStatus;
    /** メールの自動取得が実際に走る状態か（自動を選択済み・連携済み・許可ドメインあり） */
    mailAutoFetch: boolean;
    /** 接続テストの結果。押したときだけ flash で届く */
    flash: { aiTest: AiTestResult | null; aiDecisionTest: AiDecisionTestResult | null };
}>();
</script>

<template>
    <Head title="AIエンジン設定" />
    <SettingsLayout>
        <AiSection :ai="ai" :test-result="flash.aiTest" :decision-test-result="flash.aiDecisionTest"
            :mail-auto-fetch="mailAutoFetch" />
    </SettingsLayout>
</template>
