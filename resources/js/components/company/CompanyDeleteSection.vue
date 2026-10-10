<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{ companyId: number; companyName: string }>();

// 企業削除（2段階確認。ソース・分析結果・面接記録も一緒に消えることを明示する）
const confirming = ref(false);

// 削除できなかった理由（CompanyController::destroy の back()->withErrors(['delete' => …])）。
// 握り潰すと「押したのに何も起きない」になるため確認パネル内に必ず出す
const error = ref<string | null>(null);

const open = () => {
    error.value = null;
    confirming.value = true;
};

const destroy = () =>
    router.delete(`/companies/${props.companyId}`, {
        preserveScroll: true,
        // 失敗時に確認パネルを開いたままエラーを出すため、ページを作り直させない
        preserveState: true,
        // delete 以外のキーで返ってきても黙らせない
        onError: (errors) => (error.value = errors.delete ?? Object.values(errors).join(' / ')),
    });
</script>

<template>
    <!-- 危険操作は保存フォームから分離し、押し間違いを2段階で防ぐ -->
    <section>
        <h3 class="mb-[14px] border-b-2 border-conflict pb-[5px] font-serif text-[19px] font-semibold text-conflict">
            この企業を削除
        </h3>
        <template v-if="!confirming">
            <button type="button" @click="open"
                class="rounded-[2px] border border-conflict-border bg-white btn-sm text-conflict hover:bg-conflict-bg">
                削除する…
            </button>
        </template>
        <div v-else class="border-l-[3px] border-conflict bg-conflict-bg px-[14px] py-[11px] text-[13px] text-conflict-fg">
            <p class="mb-[9px] leading-[21px]">
                「{{ companyName }}」を削除します。投入したソース・分析結果・面接記録もすべて消え、元に戻せません。
            </p>
            <!-- 削除できなかった理由。押したのに消えないときは必ずここに出る -->
            <p v-if="error" class="mb-[9px] border-l-[3px] border-conflict bg-white px-3 py-2 leading-[21px] text-conflict">
                {{ error }}
            </p>
            <div class="flex gap-2">
                <button type="button" @click="destroy"
                    class="rounded-[2px] bg-conflict btn-sm font-medium text-white hover:bg-conflict-fg">
                    完全に削除する
                </button>
                <button type="button" @click="confirming = false"
                    class="rounded-[2px] border border-rule-input bg-white btn-sm text-ink hover:bg-bench">
                    やめる
                </button>
            </div>
        </div>
    </section>
</template>
