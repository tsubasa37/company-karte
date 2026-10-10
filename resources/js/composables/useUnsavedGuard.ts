import { router } from '@inertiajs/vue3';
import type { PendingVisit } from '@inertiajs/core';
import { onUnmounted } from 'vue';

/**
 * 書きかけの入力を抱えたままページを離れようとしたら止める。
 *
 * 企業ページはタブを v-show で描くのでタブ切替では入力が消えないが、別ページへ移ると
 * コンポーネントごと捨てられて入力が失われる。面接直後の所感のように書き直せない一次情報が
 * 黙って消えるため、離れる前に確認する。
 *
 * 止めるのは「別のページへ移る操作」だけに絞る。保存・削除（GET 以外）は入力を失わないし、
 * 分析の進捗ポーリング（router.reload の部分再取得）や同じ URL の取り直しは画面に留まるため、
 * ここで確認を出すと数秒おきにダイアログが出て操作できなくなる。
 */
const CONFIRM_MESSAGE = '保存していない入力があります。このページを離れると入力した内容は失われます。移動しますか？';

/** 各フォームが登録する「未保存か」の判定。1つでも true なら確認を出す */
const dirtyChecks = new Set<() => boolean>();

let listening = false;

const hasUnsaved = (): boolean => [...dirtyChecks].some((isDirty) => isDirty());

/** この訪問はいまの画面を離れるか（離れないものは確認の対象にしない） */
const leavesPage = (visit: PendingVisit): boolean => {
    if (visit.method !== 'get') {
        return false; // 保存・削除。入力はフォームに残る
    }

    if (visit.only.length > 0) {
        return false; // 部分再取得（分析の進捗ポーリング）。画面はそのまま
    }

    return new URL(String(visit.url), window.location.origin).pathname !== window.location.pathname;
};

/** タブを閉じる・リロードする操作。文言はブラウザ既定に置き換わるので内容は問わない */
const onBeforeUnload = (event: BeforeUnloadEvent): void => {
    if (!hasUnsaved()) {
        return;
    }

    event.preventDefault();
};

const startListening = (): void => {
    if (listening) {
        return;
    }
    listening = true;

    window.addEventListener('beforeunload', onBeforeUnload);

    // 解除しない（画面内の遷移を常に見張る）。登録が空なら hasUnsaved が false になり素通りする
    router.on('before', (event) => {
        if (!leavesPage(event.detail.visit) || !hasUnsaved()) {
            return;
        }

        // false を返すと Inertia が遷移を取り消す
        return window.confirm(CONFIRM_MESSAGE);
    });
};

/**
 * 未保存の入力を持つフォームから呼ぶ。
 *
 * @param isDirty 本人が書いた内容が未保存で残っているか。自動転記など本人が書いていない値は
 *                含めないこと（開いただけで確認が出ると、確認そのものが無視されるようになる）
 */
export function useUnsavedGuard(isDirty: () => boolean): void {
    startListening();
    dirtyChecks.add(isDirty);

    onUnmounted(() => {
        dirtyChecks.delete(isDirty);
    });
}
