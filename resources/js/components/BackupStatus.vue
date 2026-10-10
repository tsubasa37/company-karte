<script setup lang="ts">
import type { BackupStatusData } from '@/types/backup';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ backup: BackupStatusData }>();

const totalMb = computed(() =>
    (props.backup.totalBytes / (1024 * 1024)).toFixed(1),
);

const form = useForm({});
const run = () => form.post('/settings/backup', { preserveScroll: true });
</script>

<template>
    <section>
        <h2 class="border-b-2 border-ink pb-[5px] font-serif text-[19px] font-semibold text-ink">バックアップ</h2>
        <p class="mt-2.5 text-[12px] leading-5 text-ink-2">
            カルテの実体（database.sqlite）を storage/backups/ に退避します。分析の前にも自動で作られ、直近5世代を残して古いものから置き換えます。
        </p>

        <p v-if="backup.count > 0" class="mt-3 text-[13px] tabular-nums text-ink">
            最終バックアップ: {{ backup.latestAt }} / {{ backup.count }}世代 / 合計 {{ totalMb }} MB
        </p>
        <p v-else class="mt-3 text-[13px] text-ink">バックアップはまだありません。</p>

        <p v-if="backup.unrecognizedCount > 0" class="mt-1 text-[12px] text-ink-3">
            形式外のファイルが{{ backup.unrecognizedCount }}件あります（バックアップとして扱いません）
        </p>

        <p v-if="backup.stale"
            class="mt-2.5 border-l-[3px] border-check bg-check-bg px-[13px] py-[9px] text-[13px] leading-[21px] text-check-fg">
            最終バックアップから7日以上経っています。分析やクリップの前に手動バックアップを推奨します
        </p>

        <div class="mt-3.5 flex items-center gap-3">
            <button type="button" :disabled="form.processing" @click="run"
                class="rounded-[2px] bg-ink btn-md font-medium text-paper disabled:opacity-50">
                {{ form.processing ? '作成中…' : '今すぐバックアップ' }}
            </button>
            <span v-if="form.recentlySuccessful" class="text-[12px] text-match-fg">バックアップを作成しました</span>
        </div>
    </section>
</template>
