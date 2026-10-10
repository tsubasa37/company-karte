import { router } from '@inertiajs/vue3';
import { onUnmounted, watch } from 'vue';

/**
 * 非同期処理（分析・生成・取得）の進行中だけ部分リロードを回す。
 * active が false に落ちたら止める（完了後にリロードが回り続けない）。
 */
export function useProgressPolling(active: () => boolean, only: string[], intervalMs = 2500) {
    let timer: number | undefined;
    const stop = () => {
        if (timer) {
            clearInterval(timer);
            timer = undefined;
        }
    };

    watch(
        active,
        (isActive) => {
            if (isActive && !timer) {
                timer = window.setInterval(() => router.reload({ only }), intervalMs);
            } else if (!isActive) {
                stop();
            }
        },
        { immediate: true },
    );
    onUnmounted(stop);
}
