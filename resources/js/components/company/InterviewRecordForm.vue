<script setup lang="ts">
import { useInterviewTransfer } from '@/composables/useInterviewTransfer';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { useForm } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref, useId, watch } from 'vue';

const props = defineProps<{ companyId: number }>();

// label[for] と input[id] の紐付け用。同一画面に複数描画されても衝突しないよう useId で採番する
const uid = useId();
const fid = (key: string) => `${uid}-${key}`;

// 1面接=1レコード。保存すると「面接メモ」ソースになり、次の分析で一次情報として使われる
const form = useForm({
    interviewed_on: '',
    round: '',
    interviewer: '',
    asked: '',
    answers: '',
    impressions: '',
});
// カルテの逆質問から転記された質問を「聞いたこと」に流し込む（既に入っている文は足さない）。
// タブ切替では両方マウントされたままなので、マウント時と積まれた時の両方で反映する
const { staged, clear } = useInterviewTransfer();
// 転記した直後の「聞いたこと」。本人が書いた内容と区別するために控える
const transferred = ref('');
const applyStaged = () => {
    for (const q of staged.value) {
        if (form.asked.includes(q)) continue;
        form.asked = form.asked === '' ? q : `${form.asked}\n${q}`;
    }
    transferred.value = form.asked;
};
onMounted(applyStaged);
watch(staged, applyStaged);
// 企業をまたいで転記が持ち越されないよう、企業ページを離れたら受け渡し口を空にする
onUnmounted(clear);

// 未保存の判定。転記されただけの「聞いたこと」は本人が書いた内容ではないので数えない
// （開いただけで離脱確認が出ると、確認そのものが読み飛ばされるようになる）
useUnsavedGuard(
    () =>
        form.interviewed_on !== '' ||
        form.round !== '' ||
        form.interviewer !== '' ||
        form.answers !== '' ||
        form.impressions !== '' ||
        form.asked !== transferred.value,
);

const submit = () =>
    form.post(`/companies/${props.companyId}/interviews`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            // 保存済みの転記は用済み。残すと次の面接記録に前回の質問が混ざる
            clear();
            transferred.value = '';
        },
    });
</script>

<template>
    <!-- 逆質問・評価・面接準備タブの「面接記録」ボタンの移動先（id は useCompanyTab の TAB_OF_ANCHOR に登録） -->
    <section id="interview-record" class="scroll-mt-24">
        <h3 class="border-b-2 border-ink pb-[5px] font-serif text-[19px] font-semibold text-ink">面接記録を追加</h3>
        <p class="mt-2.5 mb-[14px] text-[12px] leading-5 text-ink-2">
            保存すると「面接メモ」ソースになります（追記専用）。再分析すると
            <span class="text-check">検証不能</span> だった主張が面接での回答で
            <span class="text-match">整合</span>／<span class="text-conflict">矛盾</span> に更新されます。
        </p>
        <form class="space-y-[14px]" @submit.prevent="submit">
            <div class="flex flex-wrap gap-[14px]">
                <div>
                    <label :for="fid('interviewed-on')" class="mb-[5px] block text-[11px] leading-4 text-ink-3">面接日（必須）</label>
                    <input :id="fid('interviewed-on')" v-model="form.interviewed_on" type="date" required
                        class="text-[13px] tabular-nums" />
                </div>
                <div>
                    <label :for="fid('round')" class="mb-[5px] block text-[11px] leading-4 text-ink-3">回次（必須）</label>
                    <input :id="fid('round')" v-model="form.round" type="text" required placeholder="例: 一次面接"
                        class="w-40 text-[13px]" />
                </div>
                <div>
                    <label :for="fid('interviewer')" class="mb-[5px] block text-[11px] leading-4 text-ink-3">面接官の役職（任意）</label>
                    <input :id="fid('interviewer')" v-model="form.interviewer" type="text" placeholder="例: 開発部長"
                        maxlength="100" class="w-48 text-[13px]" />
                </div>
            </div>
            <div>
                <label :for="fid('asked')" class="mb-[5px] block text-[11px] leading-4 text-ink-3">聞いたこと（任意）</label>
                <p v-if="staged.length"
                    class="mb-[5px] border-l-[3px] border-check bg-check-bg px-[9px] py-1 text-[11px] leading-[17px] text-check-fg">
                    カルテの逆質問から<span class="tabular-nums">{{ staged.length }}</span>件を転記しました（下の欄。編集できます）
                </p>
                <textarea :id="fid('asked')" v-model="form.asked" rows="2"
                    class="w-full text-[13px] leading-[22px]" placeholder="例: 残業時間の実績、評価の決まり方" />
            </div>
            <div>
                <label :for="fid('answers')" class="mb-[5px] block text-[11px] leading-[17px] text-ink-3">
                    回答・聞けたこと（必須。求人票の主張の検証材料になります）
                </label>
                <textarea :id="fid('answers')" v-model="form.answers" rows="4" required
                    class="w-full text-[13px] leading-[22px]" placeholder="例: 平均残業は月20h、繁忙期は30h程度との回答" />
            </div>
            <div>
                <label :for="fid('impressions')" class="mb-[5px] block text-[11px] leading-4 text-ink-3">所感（任意）</label>
                <textarea :id="fid('impressions')" v-model="form.impressions" rows="2"
                    class="w-full text-[13px] leading-[22px]" placeholder="例: 現場の裁量は大きそう" />
            </div>
            <!-- 送る欄はすべて理由を出す。1つでも欠けると「押しても何も起きない」保存拒否になる -->
            <p v-if="form.errors.interviewed_on" class="text-[11px] text-conflict">{{ form.errors.interviewed_on }}</p>
            <p v-if="form.errors.round" class="text-[11px] text-conflict">{{ form.errors.round }}</p>
            <p v-if="form.errors.interviewer" class="text-[11px] text-conflict">{{ form.errors.interviewer }}</p>
            <p v-if="form.errors.asked" class="text-[11px] text-conflict">{{ form.errors.asked }}</p>
            <p v-if="form.errors.answers" class="text-[11px] text-conflict">{{ form.errors.answers }}</p>
            <p v-if="form.errors.impressions" class="text-[11px] text-conflict">{{ form.errors.impressions }}</p>
            <button type="submit" :disabled="form.processing"
                class="rounded-[2px] bg-ink btn-md font-medium text-paper disabled:opacity-50">
                面接記録を保存
            </button>
            <span v-if="form.recentlySuccessful" class="ml-2 text-[12px] text-match-fg">保存しました</span>
        </form>
    </section>
</template>
