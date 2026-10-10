<script setup lang="ts">
/**
 * 長い自由文を行数で畳んで出し、その場で全文に開けるようにする。
 *
 * title 属性に本文を逃がさない（ホバーできない端末・読み上げ・印刷のどれでも読めないため）。
 * 「…全文」は本当に隠れているときだけ出す。隠れているかは文字数では決まらない
 * （比較表の 10rem 列と、カルテの全幅では折り返しが違う）ので、描画結果を測って決める。
 */
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = withDefaults(defineProps<{ text: string; lines?: number }>(), { lines: 2 });

const expanded = ref(false);
/** 畳んだ状態で本文がはみ出しているか。展開中は clamp が外れていて測れないので更新しない */
const clipped = ref(false);
const textEl = ref<HTMLElement | null>(null);

const measure = () => {
    const el = textEl.value;
    if (el === null || expanded.value) {
        return;
    }
    // 端数の丸めで 1px 差が出るので、それは「はみ出していない」と見る
    clipped.value = el.scrollHeight > el.clientHeight + 1;
};

// 列幅の変化・書体の読み込み後の再配置でも測り直す
let observer: ResizeObserver | undefined;

onMounted(() => {
    measure();
    observer = new ResizeObserver(measure);
    observer.observe(textEl.value!);
});
onBeforeUnmount(() => observer?.disconnect());

// 本文が差し替わっても大きさが変わらないことがあるので、描画後に測り直す
watch(() => props.text, () => void nextTick(measure));
</script>

<template>
    <span class="block">
        <!-- 行数は呼び出し側が決めるので、クラスではなくカスタムプロパティで渡す。
             clamp は display:-webkit-box が要るため、開いているときだけ block に戻す -->
        <span ref="textEl" :class="expanded ? 'block' : 'line-clamp-[var(--clamp-lines)]'"
            :style="{ '--clamp-lines': lines }">{{ text }}</span>
        <button v-if="clipped" type="button" @click="expanded = !expanded"
            class="text-[11px] text-ink-3 underline underline-offset-[3px] hover:text-conflict">
            {{ expanded ? '閉じる' : '…全文' }}
        </button>
    </span>
</template>
