<script setup lang="ts">
/** 使い方・節1「カルテができるまで」。文言は constants/helpPage.ts が正本 */
import { HELP_FLOW } from '@/constants/helpPage';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <section id="help-flow" class="scroll-mt-4 px-5">
        <h2 class="border-b-2 border-ink pb-[5px] font-serif text-[19px] font-semibold text-ink">
            カルテができるまで
        </h2>
        <p class="mt-2 text-[12px] leading-[20px] text-ink-2">
            登録から再分析までの流れです。材料が増えるほど「検証不能」が減り、判定が固まっていきます。
        </p>

        <!-- 段階は1本の罫でつなぐ（図版や矢印は持たず、縦罫と番号だけで順を示す） -->
        <ol class="mt-[14px] border-l border-rule">
            <li v-for="(s, i) in HELP_FLOW" :key="s.title" class="pb-[18px] pl-[15px] last:pb-0">
                <div class="flex flex-wrap items-baseline gap-x-[9px]">
                    <span class="font-mono text-[11px] tabular-nums text-ink-3">
                        {{ String(i + 1).padStart(2, '0') }}
                    </span>
                    <h3 class="font-serif text-[15px] font-semibold text-ink">{{ s.title }}</h3>
                    <Link v-if="s.href" :href="s.href"
                        class="text-[11px] text-ink-3 underline underline-offset-[3px] hover:text-conflict">
                        {{ s.where }}
                    </Link>
                    <span v-else class="text-[11px] text-ink-3">{{ s.where }}</span>
                </div>
                <p class="mt-[5px] font-serif text-[14px] leading-[24px] text-ink-2">{{ s.body }}</p>
            </li>
        </ol>
    </section>
</template>
