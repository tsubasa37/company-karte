import { ref } from 'vue';

/**
 * クリップボードへのコピーと「コピーしました」表示の共通状態。
 *
 * navigator.clipboard はローカルHTTPでも 127.0.0.1 は secure context 扱いなので使える。
 * 失敗（権限拒否・非対応）を握り潰すと「押したのに何も起きない」になるため、
 * copyError を立てて呼び出し側で必ず表示する（サイレント失敗禁止）。
 *
 * copiedKey は「どのボタンが成功したか」の目印（全部コピー = 'all'、個別 = 添字など）。
 */
export function useClipboardCopy(resetMs = 2000) {
    const copiedKey = ref<string | null>(null);
    const copyError = ref(false);

    const copy = async (text: string, key: string): Promise<void> => {
        try {
            await navigator.clipboard.writeText(text);
        } catch {
            copyError.value = true;

            return;
        }

        copyError.value = false;
        copiedKey.value = key;
        setTimeout(() => {
            if (copiedKey.value === key) {
                copiedKey.value = null;
            }
        }, resetMs);
    };

    return { copiedKey, copyError, copy };
}
