<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        sourceId: number;
        /**
         * 拡張クリップ由来の可能性（判定はPHP）。true なら劣化上書きの警告を強める。
         * null は「この画面では判別情報を持っていない」（一覧）で、一般的な注意だけ出す。
         */
        likelyClip?: boolean | null;
    }>(),
    { likelyClip: null },
);

// 無警告で上書きしない（再取得は既存本文を置き換えるため）。押す→内容を読む→実行の2段階。
const confirming = ref(false);
const processing = ref(false);
const page = usePage();
const error = computed(() => (page.props.errors as Record<string, string>).refetch ?? null);

const refetch = () => {
    processing.value = true;
    router.post(`/sources/${props.sourceId}/refetch`, {}, {
        preserveScroll: true,
        onFinish: () => {
            processing.value = false;
            confirming.value = false;
        },
    });
};
</script>

<template>
    <!--
      ソース詳細では操作ボタン群（flex）の中に置かれる。確認の帯と失敗の帯は
      `order-last w-full` でボタン行の下に回す（一覧では素の縦積みなので order は効かない）。
    -->
    <button v-if="!confirming" type="button" @click="confirming = true"
        class="rounded-[2px] border border-rule-input bg-white btn-sm text-ink hover:bg-bench">
        再取得
    </button>

    <div v-if="confirming"
        class="order-last w-full border-l-[3px] border-check bg-check-bg px-[14px] py-[11px] text-left text-[13px] text-check-fg">
        <p class="mb-2 leading-[21px]">
            同じURLからサーバが本文を取り直します。ページが差し替わっていれば本文が置き換わり、
            <b class="font-semibold">旧本文は変更履歴に残ります</b>（差分で確認できます）。
        </p>
        <p v-if="likelyClip === true" class="mb-2 leading-[21px]">
            このソースは<b class="font-semibold">拡張のクリップ由来の可能性があります</b>（AIの一行要約が付いています）。
            拡張はブラウザで描画し終えた本文を読むため、サーバからの再取得では
            SPAのページで本文が短く劣化することがあります。
            心配な場合はブラウザでページを開き、拡張のボタンから取り込み直してください。
        </p>
        <p v-else-if="likelyClip === null" class="mb-2 leading-[21px] text-check">
            拡張のクリップで取り込んだソースの場合、サーバからの再取得では本文が短く劣化することがあります
            （拡張はブラウザで描画し終えた本文を読むため）。判別はソース詳細ページで確認できます。
        </p>
        <div class="flex gap-2">
            <button type="button" @click="refetch" :disabled="processing"
                class="rounded-[2px] bg-check btn-sm font-medium text-white hover:bg-check-fg disabled:opacity-50">
                {{ processing ? '取得中…' : '再取得する' }}
            </button>
            <button type="button" @click="confirming = false"
                class="rounded-[2px] border border-rule-input bg-white btn-sm text-ink hover:bg-bench">
                やめる
            </button>
        </div>
    </div>

    <p v-if="error"
        class="order-last w-full border-l-[3px] border-conflict bg-conflict-bg px-3 py-2 text-left text-[12px] text-conflict-fg">
        {{ error }}
    </p>
</template>
