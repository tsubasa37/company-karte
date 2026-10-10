<script setup lang="ts">
/**
 * 使い方（/help）。
 *
 * 各画面の ⓘ（constants/help.ts）は「その画面のその項目」を説明する。どの節の ⓘ に置いても
 * 他の画面から引けないもの ── カルテができるまでの流れ・分析結果の読み方・用語 ── をここに置く。
 * 本文は constants/helpPage.ts、節の枠（見出し・id）は components/help/* が持つ。
 */
import OnboardingGuide from '@/components/company/OnboardingGuide.vue';
import HelpFlow from '@/components/help/HelpFlow.vue';
import HelpInterview from '@/components/help/HelpInterview.vue';
import HelpKarte from '@/components/help/HelpKarte.vue';
import HelpTerms from '@/components/help/HelpTerms.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';

// 目次。href は各節コンポーネントが持つ section の id と対応する
const SECTIONS = [
    { href: '#help-flow', label: 'カルテができるまで' },
    { href: '#help-karte', label: '分析結果の読み方' },
    { href: '#help-interview', label: '面接の準備と、その後' },
    { href: '#help-terms', label: '用語' },
    { href: '#help-extension', label: 'Chrome拡張の設定' },
];
</script>

<template>
    <Head title="使い方" />
    <AppLayout rail>
        <!-- 読む画面なので行長を抑える。左右余白は各節が px-5 で持つ（作業台型の規約） -->
        <div class="mx-auto max-w-[880px] py-7">
            <header class="px-5">
                <h1 class="border-b-2 border-ink pb-[5px] font-serif text-[22px] font-semibold text-ink">使い方</h1>
                <p class="mt-[10px] font-serif text-[14px] leading-[24px] text-ink-2">
                    求人票の主張を、口コミ・公的データ・面接での回答と突き合わせて「何が確かめられて、何がまだ確かめられていないか」を残すためのアプリです。個々のボタンや項目の説明は各画面の見出しにある ⓘ に入れてあります。このページには、そこに収まらない全体の流れ・分析結果の読み方・用語をまとめています。
                </p>
            </header>

            <nav aria-label="このページの目次"
                class="mt-[18px] flex flex-wrap gap-x-[18px] gap-y-1 border-t-2 border-t-ink border-b border-b-rule px-5 py-[9px] text-[12px]">
                <a v-for="s in SECTIONS" :key="s.href" :href="s.href" class="text-ink hover:text-conflict">
                    {{ s.label }}
                </a>
            </nav>

            <!-- 節は 40px 間隔で積む（カルテ本体と同じ間隔） -->
            <div class="mt-10 space-y-10">
                <HelpFlow />
                <HelpKarte />
                <HelpInterview />
                <HelpTerms />

                <section id="help-extension" class="scroll-mt-4 px-5">
                    <h2 class="border-b-2 border-ink pb-[5px] font-serif text-[19px] font-semibold text-ink">
                        Chrome拡張の設定
                    </h2>
                    <p class="mt-2 text-[12px] leading-[20px] text-ink-2">
                        見ているページをボタン1つで取り込むための、はじめの1回だけの設定です。取り込みは押したときだけ走り、自動巡回はしません。
                    </p>
                    <!-- 手順の正本は OnboardingGuide.vue（同じ文言を2箇所に持たないため、そこを再利用する） -->
                    <div class="mt-[14px]">
                        <OnboardingGuide extension-only />
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
