<script setup lang="ts">
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import type { Option } from '@/types/company';
import { useForm } from '@inertiajs/vue3';
import { useId } from 'vue';

const props = defineProps<{
    companyId: number;
    sourceTypeOptions: Option[];
}>();

// label[for] と input[id] の紐付け用。同一画面に複数描画されても衝突しないよう useId で採番する
const uid = useId();
const fid = (key: string) => `${uid}-${key}`;

const form = useForm({ type: 'jobposting', origin: 'paste', url: '', raw_text: '' });
// 未保存の判定は本文と URL だけ。種別・取り込み方法の選び直しは失って困る入力ではない
useUnsavedGuard(() => form.raw_text !== '' || form.url !== '');
const add = () =>
    form.post(`/companies/${props.companyId}/sources`, {
        preserveScroll: true,
        onSuccess: () => form.reset('raw_text', 'url'),
    });

// URL取得に失敗したときの代替手段（サーバ側のエラー文と同じ案内）へ1クリックで移る
const switchToPaste = () => {
    form.origin = 'paste';
    form.clearErrors('url');
};
</script>

<template>
    <form class="space-y-[10px] border-t border-rule pt-[18px]" @submit.prevent="add">
        <div class="flex flex-wrap gap-4">
            <div>
                <label :for="fid('type')" class="mb-1 block text-[11px] text-ink-3">種別</label>
                <select :id="fid('type')" v-model="form.type" class="w-40 text-[13px] text-ink">
                    <option v-for="o in sourceTypeOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
            </div>
            <div>
                <label :for="fid('origin')" class="mb-1 block text-[11px] text-ink-3">取り込み方法</label>
                <select :id="fid('origin')" v-model="form.origin" class="w-32 text-[13px] text-ink">
                    <option value="paste">貼り付け</option>
                    <option value="url">URL</option>
                </select>
            </div>
        </div>
        <template v-if="form.origin === 'url'">
            <label :for="fid('url')" class="block text-[11px] text-ink-3">取得元URL</label>
            <input :id="fid('url')" v-model="form.url" type="url" class="w-full text-[13px] text-ink"
                placeholder="https://…" />
            <p class="text-[12px] leading-5 text-ink-2">
                ログインが必要なサイトは、サーバから取得しても会員登録の案内しか取れません。
                ブラウザでページを開いて<b class="font-semibold text-ink">拡張のボタンで取り込んでください</b>。
            </p>
        </template>
        <template v-else>
            <label :for="fid('raw-text')" class="block text-[11px] text-ink-3">本文</label>
            <textarea :id="fid('raw-text')" v-model="form.raw_text" rows="10"
                class="w-full text-[13px] leading-[22px] text-ink" placeholder="求人票・口コミ本文を貼り付け" />
        </template>
        <template v-if="form.errors.url">
            <p class="text-[12px] text-conflict">{{ form.errors.url }}</p>
            <!-- 取得できないページは貼り付けが確実。本文をコピーしてすぐ切り替えられるようにする -->
            <button type="button" @click="switchToPaste"
                class="rounded-[2px] border border-rule-input bg-white btn-sm text-ink hover:bg-bench">
                「貼り付け」に切り替える
            </button>
        </template>
        <p v-if="form.errors.raw_text" class="text-[12px] text-conflict">{{ form.errors.raw_text }}</p>
        <button type="submit" :disabled="form.processing"
            class="rounded-[2px] bg-ink btn-md font-medium text-paper disabled:opacity-50">
            {{ form.origin === 'url' ? 'URLから取得して追加' : 'ソースを追加' }}
        </button>
        <span v-if="form.recentlySuccessful" class="ml-2 text-[12px] text-match-fg">追加しました</span>
    </form>
</template>
