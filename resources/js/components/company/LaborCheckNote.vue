<script setup lang="ts">
import type { LaborNoteHistoryEntry } from '@/types/company';

// 労基公表リスト突合の3状態を描き分ける。
// 「該当あり」「突合済み・該当なし」「未突合」を同じ無表示にまとめると、
// 最終突合より後に登録した企業（未突合）が「問題なし」に見えてしまう。
defineProps<{
    laborNote: string | null;
    /** 未突合は null。プロパティ自体が無い場合は「不明」として扱う */
    laborCheckedAt?: string | null;
    /** 過去に一致していた記録（新しい順） */
    laborNoteHistory?: LaborNoteHistoryEntry[];
}>();
</script>

<template>
    <div v-if="laborNote"
        class="mt-3 border-l-[3px] border-conflict bg-conflict-bg px-[13px] py-[9px] text-[13px] leading-[21px] text-conflict-fg">
        {{ laborNote }}
        <span v-if="laborCheckedAt" class="text-[11px] text-conflict">（突合: {{ laborCheckedAt }}）</span>
    </div>
    <p v-else-if="laborCheckedAt" class="mt-3 text-[12px] text-ink-3">
        労基公表リスト突合済み・該当なし（{{ laborCheckedAt }}）
    </p>
    <p v-else-if="laborCheckedAt === null" class="mt-3 text-[12px] text-check">
        労基公表リストとの突合は未実施です（ダッシュボードの「労基リスト突合」で実行できます）
    </p>
    <p v-else class="mt-3 text-[12px] text-ink-3">労基公表リストの突合状態は未取得です</p>

    <!-- 一度載った事実を黙って消さない。ただし「消えた=解消」ではないことも書く -->
    <details v-if="laborNoteHistory?.length" class="mt-2 text-[12px] text-ink-2">
        <summary class="cursor-pointer">過去に公表リストへ一致していた記録（{{ laborNoteHistory.length }}件）</summary>
        <ul class="mt-[6px] ml-5 list-disc space-y-1 leading-5">
            <li v-for="(h, i) in laborNoteHistory" :key="i">
                {{ h.clearedAt ?? '日時不明' }} に注記が消えました（理由: {{ h.reason }}）<br />{{ h.note }}
            </li>
        </ul>
        <p class="mt-[6px]">公表リストは掲載期間（約1年）で入れ替わるため、消えたことは是正の確認にはなりません。</p>
    </details>
</template>
