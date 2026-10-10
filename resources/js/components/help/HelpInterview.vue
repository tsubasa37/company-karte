<script setup lang="ts">
/** 使い方・節3「面接の準備と、その後」。文言は constants/helpPage.ts が正本 */
import { HELP_INTERVIEW } from '@/constants/helpPage';
</script>

<template>
    <section id="help-interview" class="scroll-mt-4 px-5">
        <h2 class="border-b-2 border-ink pb-[5px] font-serif text-[19px] font-semibold text-ink">
            面接の準備と、その後
        </h2>
        <p class="mt-2 text-[12px] leading-[20px] text-ink-2">
            企業カルテの「面接準備」タブには3つの節があります。並びは上から ブリーフィング → 論点 → 逆質問 です。
        </p>

        <div class="mt-[14px] border-b border-rule">
            <div v-for="item in HELP_INTERVIEW" :key="item.title" class="border-t border-rule py-[13px]">
                <h3 class="font-serif text-[15px] font-bold text-ink">{{ item.title }}</h3>
                <p class="mt-[5px] font-serif text-[14px] leading-[24px] text-ink-2">{{ item.what }}</p>
                <p class="mt-[5px] font-serif text-[14px] leading-[24px] text-ink-2">{{ item.how }}</p>
            </div>
        </div>

        <h3 class="mt-[18px] font-serif text-[15px] font-bold text-ink">面接が終わったら</h3>
        <p class="mt-[5px] font-serif text-[14px] leading-[24px] text-ink-2">
            聞けた回答を、ソースタブから面接メモとして投入します。投入すると、追跡中の論点に答えが出たかをAIが照合し、状態の確定はPHPが行います。そのうえで再分析すると、面接メモが一次情報として裏取りに使われ、検証不能だった主張が 整合 か 矛盾 に変わることがあります。
        </p>
        <p class="mt-[5px] font-serif text-[14px] leading-[24px] text-ink-2">
            ブリーフィングは材料が動くと古くなります（生成後にカルテ・面接記録が動くと警告が出ます）。次の面接の前に作り直してください。
        </p>
    </section>
</template>
