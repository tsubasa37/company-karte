<script setup lang="ts">
import HelpTip from '@/components/HelpTip.vue';
import { useCompanyTabNav } from '@/composables/useCompanyTab';
import { HELP } from '@/constants/help';
import type { Coverage } from '@/types/issue';
import { computed } from 'vue';

const props = defineProps<{ coverage: Coverage }>();

// 同じページ内のタブ移動。Link だとサーバーへ取り直しに行き、入力中の欄も作り直される
const { setTab } = useCompanyTabNav();

// 材料が揃っている企業に毎回「全部OK」の帯を見せない。不足があるときだけ展開する
const hasMissing = computed(() => props.coverage.missingCount > 0);

// 求人票が無い＝おすすめ度が判定保留から動かない。他の不足とは扱いを変える
const isBlocking = computed(() => props.coverage.level === 'insufficient');
</script>

<template>
    <section v-if="hasMissing" id="karte-coverage" class="scroll-mt-12">
        <h3 class="border-t-2 border-t-ink pt-[7px] font-serif text-[15px] font-bold">
            材料の充足
            <HelpTip :help="HELP.coverage" />
            <span class="ml-[10px] font-sans text-[11px] font-normal text-ink-3">
                {{ coverage.items.length }}項目中{{ coverage.missingCount }}件が不足
            </span>
        </h3>

        <!-- 現状は数えた事実だけを出す。何を足すかは下のヒントで示す -->
        <div class="mt-[10px] flex flex-wrap border border-rule bg-surface-alt">
            <div v-for="item in coverage.items" :key="item.key"
                class="flex-1 basis-[130px] border-r border-rule-thin px-3 py-[9px] last:border-r-0">
                <span class="block text-[11px] tracking-[.04em] text-ink-3">{{ item.label }}</span>
                <span class="font-mono text-[13px] tabular-nums"
                    :class="item.satisfied ? 'text-match' : 'text-conflict'">{{ item.detail }}</span>
            </div>
        </div>

        <p class="border border-t-0 border-rule px-3 py-[7px] text-[11.5px] leading-[19px]"
            :class="isBlocking ? 'border-conflict-border bg-conflict-bg text-conflict-fg' : 'bg-check-bg text-check-fg'">
            <template v-for="(item, i) in coverage.missing" :key="item.key">
                <span v-if="i > 0"> / </span>{{ item.hint }}
            </template>
        </p>

        <p class="mt-[6px] text-[12px] text-ink-3">
            <button type="button" @click="setTab('sources')"
                class="text-ink underline underline-offset-[3px] hover:text-conflict">ソースタブから投入</button>
            して再分析すると、判定の材料が増えます。
        </p>
    </section>
</template>
