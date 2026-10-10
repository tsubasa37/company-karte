<script setup lang="ts">
import AiUsagePanel from '@/components/AiUsagePanel.vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import type { AiUsageData } from '@/types/ai-usage';
import { Head } from '@inertiajs/vue3';

defineProps<{ aiUsage: AiUsageData }>();
</script>

<template>
    <Head title="AI の使用状況" />
    <SettingsLayout>
        <AiUsagePanel :ai-usage="aiUsage" />
    </SettingsLayout>
</template>
