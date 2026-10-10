<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

const props = defineProps<{ autoFetch: boolean; connected: boolean }>();

// 保存ボタンを置かず、選んだ時点で効かせる（選んだのに未保存、という状態を作らない）
const form = useForm({ auto_fetch: props.autoFetch });
const change = (auto: boolean) => {
    form.auto_fetch = auto;
    form.put('/settings/mail/auto-fetch', { preserveScroll: true });
};
</script>

<template>
    <!-- 自動にすると、押していなくても取り込みと抽出（AI）が走る。選ぶのは必ず人 -->
    <fieldset class="mt-3 border-t border-rule pt-2.5">
        <legend class="sr-only">取り込み方法</legend>
        <p class="text-[12px] font-semibold text-ink">取り込み方法</p>

        <label class="mt-1.5 flex items-baseline gap-2 text-[12px] leading-5 text-ink">
            <input type="radio" name="auto_fetch" :checked="!autoFetch" :disabled="form.processing" @change="change(false)" />
            <span>手動<span class="ml-2 text-ink-2">「いま取り込む」を押したときだけ取得します</span></span>
        </label>
        <label class="mt-1 flex items-baseline gap-2 text-[12px] leading-5 text-ink">
            <input type="radio" name="auto_fetch" :checked="autoFetch" :disabled="form.processing" @change="change(true)" />
            <span>自動<span class="ml-2 text-ink-2">15分おきに取得します（dev.sh 起動中のみ）</span></span>
        </label>

        <p v-if="autoFetch"
            class="mt-2 border-l-[3px] border-check bg-check-bg px-[11px] py-[7px] text-[12px] leading-[19px] text-check-fg">
            自動の間は、ボタンを押していなくても取り込みとメールの抽出（AI）が走ります。抽出・判定にAPIを選んでいる場合はその分が課金されます。
        </p>

        <!-- 連携前でも選べるが、実際に走るのは連携してから。設定と実態のずれを隠さない -->
        <p v-if="!connected" class="mt-2 text-[12px] leading-[19px] text-ink-3">
            まだ Gmail と連携していないため、どちらを選んでも取得は行われません。連携すると、ここで選んだ方法で動きます。
        </p>
    </fieldset>
</template>
