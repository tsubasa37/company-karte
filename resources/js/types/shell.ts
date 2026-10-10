/**
 * 共通シェル（AppLayout / SideRail / StatusBar）の型。
 * 型は Vue ファイル内には置かず、ここに集約する。
 */

/** 状態バーの値の意味。色は意味で決める（正常＝墨 / 要確認＝黄 / 異常＝赤） */
export type StatusTone = 'normal' | 'check' | 'conflict';

/** 状態バーの1項目。tone を省くと normal */
export type StatusItem = {
    label: string;
    value: string | number;
    note?: string;
    tone?: StatusTone;
};

/** 左レールのナビ右端に出す件数バッジの意味。要対応＝danger / 注意＝warn */
export type BadgeTone = 'normal' | 'danger' | 'warn';

/** バッジは件数だけ渡すか、意味を添えて渡す */
export type Badge = string | number | { text: string; tone?: BadgeTone };

/** href（AppLayout の NAV のキー）→ バッジ */
export type NavBadges = Record<string, Badge>;

/** 左レールのナビ1件 */
export type NavItem = { href: string; label: string };

/** 左レールで現在地のナビ行の下に開く節（設定の5節など） */
export type RailSection = {
    href: string;
    label: string;
    /** 節名の下に添える現在値。無い節は省く（推測値や「—」を出さない） */
    value?: string | null;
    /** 現在値の色クラス */
    valueClass?: string;
};

/** ナビの href → その配下の節 */
export type RailSections = Partial<Record<string, RailSection[]>>;
