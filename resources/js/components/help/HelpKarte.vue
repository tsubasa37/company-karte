<script setup lang="ts">
/** 使い方・節2「分析結果の読み方」。文言は constants/helpPage.ts が正本 */
import { HELP_KARTE, HELP_VERDICTS } from '@/constants/helpPage';
import { VERDICT_META } from '@/constants/verdict';
</script>

<template>
    <section id="help-karte" class="scroll-mt-4 px-5">
        <h2 class="border-b-2 border-ink pb-[5px] font-serif text-[19px] font-semibold text-ink">
            分析結果の読み方
        </h2>
        <p class="mt-2 text-[12px] leading-[20px] text-ink-2">
            企業カルテの「評価」タブは、上から次の順に並びます。判定そのものより、その根拠と突合先を見るための画面です。項目ごとの細かい条件は、カルテの各節の見出しにある ⓘ に入っています。
        </p>

        <div class="mt-[14px] border-b border-rule">
            <div v-for="item in HELP_KARTE" :key="item.key" class="border-t border-rule py-[13px]">
                <h3 class="font-serif text-[15px] font-bold text-ink">{{ item.title }}</h3>
                <p class="mt-[5px] font-serif text-[14px] leading-[24px] text-ink-2">{{ item.what }}</p>
                <p class="mt-[5px] font-serif text-[14px] leading-[24px] text-ink-2">{{ item.how }}</p>

                <template v-if="item.key === 'verdicts'">
                    <!-- 判定4値の凡例。文言と色はカルテ本体と同じ VERDICT_META を使う（二重に持たない） -->
                    <dl class="mt-[10px] border border-rule bg-surface">
                        <div v-for="v in HELP_VERDICTS" :key="v.key"
                            class="flex flex-wrap items-baseline gap-x-[10px] border-b border-rule-thin px-3 py-[7px] last:border-b-0">
                            <dt class="px-[6px] text-[12px] font-medium whitespace-nowrap"
                                :class="VERDICT_META[v.key].cls">
                                {{ VERDICT_META[v.key].label }}
                            </dt>
                            <dd class="flex-1 text-[12px] leading-[20px] text-ink-2">{{ v.note }}</dd>
                        </div>
                    </dl>
                    <!-- 例は架空。実在の企業名・実データは載せない -->
                    <p class="mt-[10px] border-l-[3px] border-rule bg-bench px-3 py-2 text-[12px] leading-[20px] text-ink-2">
                        例）「残業は月平均20時間」という求人票の主張に対し、投稿時期の違う複数の口コミが「繁忙期は月40時間を超える」と述べていれば 矛盾。口コミも公式数値も無ければ 検証不能 で、口コミや面接メモを足して再分析すると 整合 か 矛盾 に変わります。
                    </p>
                </template>
            </div>
        </div>
    </section>
</template>
