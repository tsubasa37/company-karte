<script setup lang="ts">
import ProfileSection from '@/components/settings/ProfileSection.vue';
import type { SettingsNavValues } from '@/constants/settings';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import type { ProfileData } from '@/types/company';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ profile: ProfileData | null }>();

// この画面に届く props で分かるのはプロファイルの節だけ。他の節の現在値は目次に出さない
const navValues = computed<SettingsNavValues>(() => {
    const p = props.profile;
    const filled = Boolean(
        p &&
            (p.career_summary ||
                p.axis ||
                p.current_annual_salary !== null ||
                p.must_json?.length ||
                p.want_json?.length ||
                p.ng_json?.length),
    );

    return { '/settings/profile': filled ? { text: '設定済み' } : { text: '未設定', tone: 'check' } };
});
</script>

<template>
    <Head title="プロファイル設定" />
    <SettingsLayout :nav-values="navValues">
        <ProfileSection :profile="profile" />
    </SettingsLayout>
</template>
