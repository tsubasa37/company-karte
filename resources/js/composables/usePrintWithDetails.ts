import { onMounted, onUnmounted } from 'vue';

/**
 * 印刷時に折りたたみ（<details>）をすべて開く。
 *
 * details の開閉は open 属性でしか変えられず CSS（@media print）では開けないため、
 * JS で開く。ボタン内で開くのではなく beforeprint で拾うので、ブラウザの印刷
 * （Cmd+P・メニューからの印刷）でも危険サイン・検証不能が出力から欠落しない。
 *
 * 印刷後（afterprint）は元の開閉状態に戻す。画面の折りたたみは「今は畳んでおく」という
 * 利用者の選択なので、印刷のために書き換えたままにしない。
 *
 * beforeprint / afterprint は Chrome・Firefox で発火する。Safari は両方発火しないため
 * 何も起きない（開いたまま戻らない、という中途半端な状態にはならない）。
 */
export function usePrintWithDetails() {
    // beforeprint で開いた（= 元は閉じていた）ものだけを覚えて、afterprint で閉じ直す
    let opened: HTMLDetailsElement[] = [];

    const openAll = () => {
        opened = [...document.querySelectorAll('details')].filter((d) => !d.open);
        for (const d of opened) {
            d.open = true;
        }
    };

    const restore = () => {
        for (const d of opened) {
            d.open = false;
        }
        opened = [];
    };

    onMounted(() => {
        window.addEventListener('beforeprint', openAll);
        window.addEventListener('afterprint', restore);
    });

    onUnmounted(() => {
        window.removeEventListener('beforeprint', openAll);
        window.removeEventListener('afterprint', restore);
    });

    return { print: () => window.print() };
}
