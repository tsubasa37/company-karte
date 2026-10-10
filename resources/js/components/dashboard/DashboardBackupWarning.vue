<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

// 停滞判定と文言はPHP（BuildDashboardAction / BuildBackupStatusAction）で確定済み。
// 正常なら warning は null で、このブロックは描画されない
defineProps<{
    warning: string | null;
    /** 台帳の通し番号（確定は Dashboard.vue） */
    no: number;
}>();
</script>

<template>
    <section v-if="warning"
        class="border-b border-b-rule-thin border-l-[3px] border-l-check bg-surface py-[13px] pr-5 pl-[17px]">
        <div class="grid grid-cols-[2.4rem_minmax(0,1fr)] gap-3">
            <div class="pt-px text-right">
                <div class="font-mono text-[11px] tabular-nums text-ink-4">{{ no }}</div>
                <div class="font-serif text-[15px] leading-[1.2] text-check" aria-hidden="true">○</div>
            </div>
            <div class="min-w-0">
                <h2 class="font-serif text-[15px] font-semibold text-check">バックアップ</h2>
                <p class="mt-[5px] text-[12px] leading-5 text-ink-2">{{ warning }}</p>
                <Link href="/settings/backup"
                    class="mt-2 inline-block text-[12px] text-ink-2 underline underline-offset-[3px] hover:text-conflict">
                    設定画面で今すぐバックアップ
                </Link>
            </div>
        </div>
    </section>
</template>
