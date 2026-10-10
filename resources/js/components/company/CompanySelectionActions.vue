<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps<{ selected: number[] }>();
const emit = defineEmits<{ clear: [] }>();

const compareSelected = () => router.get('/compare', { ids: props.selected });

// 削除は単体削除（基本情報タブ）と同じ2段階確認。件数と巻き添えで消えるものを明示する
const confirming = ref(false);
const deleting = ref(false);
// バリデーション失敗（存在しないID混入等）を黙って握り潰さない
const deleteError = ref<string | null>(null);
const destroySelected = () =>
    router.delete('/companies', {
        data: { ids: props.selected },
        onStart: () => {
            deleting.value = true;
            deleteError.value = null;
        },
        onFinish: () => (deleting.value = false),
        onSuccess: () => emit('clear'),
        onError: (errors) => (deleteError.value = Object.values(errors)[0] ?? '削除に失敗しました。'),
    });

// 選択を変えたら確認状態を戻す（別の選択に前の確認を引き継がない）
watch(
    () => props.selected,
    () => (confirming.value = false),
);
</script>

<template>
    <!-- ツールバー（CompanyFilterBar）の下に出る帯。ツールバーは選択中も残す（検索条件を変えながら対象を集めるため） -->
    <div v-if="selected.length">
        <div class="flex flex-wrap items-center gap-3 border-b border-rule-input bg-ink px-5 py-2 text-rail-fg">
            <span class="font-mono text-[12px] tabular-nums">{{ selected.length }} 選択中</span>
            <button type="button" @click="compareSelected"
                :aria-label="`選択した${selected.length}社を比較`"
                class="rounded-[2px] border border-transparent bg-paper btn-sm font-medium text-ink">
                比較
            </button>
            <button v-if="!confirming" type="button" @click="confirming = true"
                :aria-label="`選択した${selected.length}社を削除`"
                class="rounded-[2px] border border-rail-danger btn-sm text-rail-danger">
                削除…
            </button>
            <button type="button" @click="emit('clear')"
                class="text-[12px] text-rail-muted underline underline-offset-[3px] hover:text-paper">
                選択解除
            </button>
            <span class="ml-auto text-[11px] text-rail-muted">絞り込みで隠れた選択も保持されます</span>
        </div>

        <div v-if="confirming"
            class="flex flex-wrap items-center gap-[10px] border-b border-rule-input bg-conflict-bg px-5 py-[10px] text-[12px] text-conflict-fg">
            <span class="leading-[19px]">
                選択した{{ selected.length }}社を削除します。各社の投入ソース・分析結果・面接記録もすべて消え、元に戻せません。
            </span>
            <button type="button" @click="destroySelected" :disabled="deleting"
                class="rounded-[2px] bg-conflict btn-sm font-medium text-white disabled:opacity-50">
                {{ deleting ? '削除中…' : '完全に削除する' }}
            </button>
            <button type="button" @click="confirming = false"
                class="rounded-[2px] border border-rule-input bg-white btn-sm">
                やめる
            </button>
            <p v-if="deleteError" class="w-full text-[11px] font-medium">削除できませんでした: {{ deleteError }}</p>
        </div>
    </div>
</template>
