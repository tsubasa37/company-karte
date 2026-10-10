import { router } from '@inertiajs/vue3';
import { inject, nextTick, onMounted, onUnmounted, provide, ref, type InjectionKey, type Ref } from 'vue';

export type CompanyTab = 'evaluation' | 'interview' | 'sources' | 'info';

const TABS: CompanyTab[] = ['evaluation', 'interview', 'sources', 'info'];

/**
 * 旧タブ名 → 現タブ名。カルテタブを「評価」と「面接準備」に割る前は karte 1つだった。
 * ダッシュボード・検索・保存済みURLが今も ?tab=karte を送ってくるので評価タブとして受ける
 * （許可リストは PHP 側 CompanyController::TAB_ALIASES と対で持つ）。
 */
const TAB_ALIASES: Record<string, CompanyTab> = { karte: 'evaluation' };

/**
 * 節（アンカー）を持つタブ。タブは v-show（別タブは display:none）なので、
 * 別タブの節を指す #リンクはブラウザだけでは飛べない。どのタブを開けばその節が見えるかをここで引く。
 */
const TAB_OF_ANCHOR: Record<string, CompanyTab> = {
    'karte-coverage': 'evaluation',
    'karte-summary': 'evaluation',
    'karte-verdicts': 'evaluation',
    'karte-rhetoric': 'evaluation',
    'karte-reviews': 'evaluation',
    'karte-briefing': 'interview',
    'karte-issues': 'interview',
    'karte-questions': 'interview',
    'interview-record': 'sources',
};

const normalize = (value: string | null): CompanyTab | null => {
    if (value === null) {
        return null;
    }
    const name = TAB_ALIASES[value] ?? value;

    return TABS.includes(name as CompanyTab) ? (name as CompanyTab) : null;
};

/** 現在の URL のハッシュが指す節（このアプリの節でなければ null） */
const currentAnchor = (): string | null => {
    const id = window.location.hash.slice(1);

    return id in TAB_OF_ANCHOR ? id : null;
};

/**
 * 節へ移動する。<details>（危険サイン等）は開いてからでないと中身へ飛べないため先に開く。
 * 節そのものが無い場合（スキップされたステップ等）は素の #リンクと同じく何も起きない。
 */
const revealAnchor = (id: string): void => {
    const el = document.getElementById(id);
    if (el === null) {
        return;
    }
    if (el instanceof HTMLDetailsElement) {
        el.open = true;
    }
    el.scrollIntoView();
};

export interface CompanyTabNav {
    tab: Ref<CompanyTab>;
    /** タブを切り替える。anchor を渡すと切替後にその節まで送る */
    setTab: (name: CompanyTab, anchor?: string) => void;
}

const companyTabKey: InjectionKey<CompanyTabNav> = Symbol('companyTab');

/**
 * 企業ページのタブ選択（URL指定 > 直前に開いていたタブ > 既定）。
 * ただしハッシュが節を名指ししている場合は、その節を持つタブが最優先
 * （?tab=karte#karte-issues のように、タブ名より行き先が具体的に書かれているため）。
 *
 * props ではなく location.search を優先する: 戻る操作では Inertia が古い props を復元するが
 * URL は replaceState 後のものが残るため、URL を正としないとタブ表示と URL が食い違う。
 *
 * sessionStorage を挟む理由: このアプリは Referrer-Policy: no-referrer のため、保存・取得の
 * POST 後の back() は ?tab= を失った素の URL に戻る（replaceState はサーバーに伝わらない）。
 * その再構築でタブが既定に戻ると、直前の操作のエラーが別タブに隠れて見えなくなる。
 */
export function useCompanyTab(companyId: number, fallback: CompanyTab): CompanyTabNav {
    const storageKey = `company-tab:${companyId}`;
    const fromUrl = normalize(new URLSearchParams(window.location.search).get('tab'));
    const fromSession = normalize(sessionStorage.getItem(storageKey));
    // replaceState でハッシュが落ちるため、初回描画後に送るぶんをここで控えておく
    const initialAnchor = currentAnchor();
    const fromAnchor = initialAnchor === null ? null : TAB_OF_ANCHOR[initialAnchor];

    const tab = ref<CompanyTab>(fromAnchor ?? fromUrl ?? fromSession ?? fallback);
    sessionStorage.setItem(storageKey, tab.value);
    // 復元したタブを URL にも同期する（この直後にリロードしても同じタブが開くように）
    window.history.replaceState(window.history.state, '', `?tab=${tab.value}`);

    // タブ切替は URL にも反映する（サーバーへの再取得はしない）。
    // v-show 描画のため各タブの入力（選考メモ・貼り付け欄）は切替で消えない
    const setTab = (name: CompanyTab, anchor?: string) => {
        tab.value = name;
        sessionStorage.setItem(storageKey, name);
        window.history.replaceState(window.history.state, '', `?tab=${name}`);
        if (anchor !== undefined) {
            // 描画（display 復帰）を待たないと、隠れている要素へはスクロールできない
            void nextTick(() => revealAnchor(anchor));
        }
    };

    /**
     * 別タブの節を指す #リンク（主張検証テーブルの「追跡中」など）を受ける。
     * ブラウザは display:none の要素へ飛べずハッシュだけが変わるので、その節を持つタブへ切り替える。
     */
    const onHashChange = () => {
        const anchor = currentAnchor();
        if (anchor === null || TAB_OF_ANCHOR[anchor] === tab.value) {
            return; // 同じタブ内ならブラウザが飛ばしてくれる
        }
        setTab(TAB_OF_ANCHOR[anchor], anchor);
    };

    // router.on の解除関数（onUnmounted で呼ぶ）
    let stopNavigate: (() => void) | undefined;

    onMounted(() => {
        window.addEventListener('hashchange', onHashChange);
        // 旧タブ名（?tab=karte）で来た場合、setup 時の replaceState は Inertia が
        // ページ確定時に書き戻すため URL に残る（表示・保存は正規化済みなので実害は無い）。
        // 共有された URL が古い名前のまま増えないよう、Inertia の確定後に書き直す。
        // router.on('navigate') は初回描画にも発火し、そこが Inertia が URL を確定した後になる
        stopNavigate = router.on('navigate', () => {
            if (new URLSearchParams(window.location.search).get('tab') !== tab.value) {
                window.history.replaceState(window.history.state, '', `?tab=${tab.value}`);
            }
        });
        // 初回表示時のハッシュはブラウザが飛べない（描画前で節がまだ無い）ので、ここで送る
        if (initialAnchor !== null) {
            revealAnchor(initialAnchor);
        }
    });
    onUnmounted(() => {
        window.removeEventListener('hashchange', onHashChange);
        stopNavigate?.();
    });

    const nav: CompanyTabNav = { tab, setTab };
    // タブ切替は節ナビ（KarteView・TabInterview）からも呼ぶ。中継用の props を各タブに配らない
    provide(companyTabKey, nav);

    return nav;
}

/** 企業ページ配下からタブを操作する。企業ページの外で呼ばれたら例外にする（黙って無反応にしない） */
export function useCompanyTabNav(): CompanyTabNav {
    const nav = inject(companyTabKey);
    if (nav === undefined) {
        throw new Error('useCompanyTabNav は useCompanyTab を呼ぶ企業ページ（Companies/Show）の配下でのみ使えます');
    }

    return nav;
}
