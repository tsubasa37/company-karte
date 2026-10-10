<script setup lang="ts">
/** 使い方・節4「用語」。文言は constants/helpPage.ts が正本 */
import { HELP_TERMS } from '@/constants/helpPage';
</script>

<template>
    <section id="help-terms" class="scroll-mt-4 px-5">
        <h2 class="border-b-2 border-ink pb-[5px] font-serif text-[19px] font-semibold text-ink">用語</h2>
        <p class="mt-2 text-[12px] leading-[20px] text-ink-2">
            このアプリは、解釈・判定・要約をAIに、数値・照合・確定計算をPHPに分けています。どちらが決めた値かで結果の受け取り方（AIなら根拠を読む・PHPなら入力を直す）が変わるため、語ごとに「決めるのは誰か」を添えています。
        </p>

        <dl class="mt-[14px] border-t border-rule">
            <div v-for="t in HELP_TERMS" :key="t.term" class="border-b border-rule-thin py-[11px] sm:flex sm:gap-[18px]">
                <dt class="sm:w-[168px] sm:shrink-0">
                    <span class="font-serif text-[15px] font-semibold text-ink">{{ t.term }}</span>
                    <span class="mt-[2px] block text-[11px] leading-[17px] text-ink-3">決めるのは {{ t.by }}</span>
                </dt>
                <dd class="mt-[4px] font-serif text-[14px] leading-[24px] text-ink-2 sm:mt-0">{{ t.body }}</dd>
            </div>
        </dl>
    </section>
</template>
