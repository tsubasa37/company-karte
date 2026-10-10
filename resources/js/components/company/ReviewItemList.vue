<script setup lang="ts">
import type { ReviewItemView } from '@/types/company';

defineProps<{
    items: ReviewItemView[];
    splitAt: string | null;
}>();
</script>

<template>
    <section class="mt-[30px]">
        <div class="flex flex-wrap items-baseline justify-between gap-[10px] border-b-2 border-ink pb-[5px]">
            <h2 class="font-serif text-[18px] font-semibold text-ink">口コミ（AIで1件ずつに分割）</h2>
            <span v-if="splitAt" class="font-mono text-[12px] tabular-nums text-ink-3">{{ items.length }}件 / 分割: {{ splitAt }}</span>
        </div>

        <p v-if="splitAt === null" class="mt-[10px] text-[12px] leading-5 text-ink-2">
            分割処理の順番待ちです（queue が動いていれば数十秒〜数分で完了します。次の分析実行時にも自動で処理されます）。
        </p>
        <p v-else-if="items.length === 0" class="mt-[10px] text-[12px] leading-5 text-ink-2">
            このページから口コミは見つかりませんでした。
        </p>

        <ul v-else class="mt-3">
            <li v-for="item in items" :key="item.id" class="border-b border-rule-thin py-[14px]">
                <div class="flex flex-wrap items-baseline gap-2 text-[11px]">
                    <span v-if="item.stance" class="rounded-[2px] bg-canvas px-[7px] py-[2px] text-ink-2">{{ item.stance }}</span>
                    <span v-if="item.category"
                        class="rounded-[2px] border border-rule-input bg-white px-[7px] py-[2px] text-ink">{{ item.category }}</span>
                    <span v-if="item.jobType" class="rounded-[2px] bg-split-bg px-[7px] py-[2px] text-split-fg">{{ item.jobType }}</span>
                    <span v-if="item.sourceHost" class="pl-[7px] font-mono text-[10px] text-ink-3">{{ item.sourceHost }}</span>
                    <span class="border-l-2 pl-[6px]"
                        :class="item.isRecent ? 'border-match text-match' : 'border-rule-input text-ink-3'">
                        {{ item.periodLabel }}
                    </span>
                </div>
                <!-- 投稿日と在籍時期は別物なので必ず両方出す（未取得も明示する） -->
                <p class="mt-[7px] text-[11px] text-ink-3">
                    投稿: <span :class="item.postedPeriod ? 'text-ink' : 'text-ink-4'">{{ item.postedPeriod ?? '未取得' }}</span>
                    ／ 在籍: <span :class="item.tenurePeriod ? 'text-ink' : 'text-ink-4'">{{ item.tenurePeriod ?? '未取得' }}</span>
                </p>
                <p class="mt-[6px] font-serif text-[14px] leading-[25px] whitespace-pre-wrap text-ink">{{ item.body }}</p>
            </li>
        </ul>
    </section>
</template>
