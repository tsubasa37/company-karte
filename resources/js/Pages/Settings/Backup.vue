<script setup lang="ts">
import BackupStatus from '@/components/BackupStatus.vue';
import type { SettingsNavValues } from '@/constants/settings';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import type { BackupStatusData } from '@/types/backup';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ backup: BackupStatusData }>();

// この画面に届く props で分かるのはバックアップの節だけ。他の節の現在値は目次に出さない
const navValues = computed<SettingsNavValues>(() => {
    const b = props.backup;

    if (b.count === 0) return { '/settings/backup': { text: 'まだありません', tone: 'check' } };
    if (b.stale) return { '/settings/backup': { text: '7日以上経過', tone: 'check' } };

    return { '/settings/backup': { text: b.latestAt ?? '—' } };
});
</script>

<template>
    <Head title="バックアップ" />
    <SettingsLayout :nav-values="navValues">
        <BackupStatus :backup="backup" />
    </SettingsLayout>
</template>
