import { readonly, ref } from 'vue';

/**
 * 逆質問（カルテタブ）→「面接記録を追加」フォーム（ソースタブ）への転記の受け渡し。
 *
 * 2つのコンポーネントは親子関係になく、共通の親（Pages/Companies/Show）を挟むため
 * props / emit では繋がらない。タブは v-show 描画で両方が同時にマウントされているので、
 * モジュールスコープの ref 1つを共有の受け渡し口にする（Inertia 外の状態はこれだけ）。
 *
 * 企業をまたいだ持ち越しは、受け取り側（InterviewRecordForm）のアンマウント時に clear()
 * することで防ぐ。フォームは企業ページに必ず同居するので、ページを離れれば必ず空になる。
 */
const staged = ref<string[]>([]);

export function useInterviewTransfer() {
    /** 転記する質問を積む。同じ質問は二重に積まない（押し直し・全件転記の重複を防ぐ） */
    const stage = (questions: string[]): void => {
        staged.value = [...staged.value, ...questions.filter((q) => !staged.value.includes(q))];
    };

    const clear = (): void => {
        staged.value = [];
    };

    return { staged: readonly(staged), stage, clear };
}
