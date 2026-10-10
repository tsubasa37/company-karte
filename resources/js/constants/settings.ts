import type { SettingsSection } from '@/types/settings';

/**
 * 設定の節。1節=1ページで、サイドの並びと URL の対応はここだけで決める。
 * 追加するときは routes/web.php の settings.* と Pages/Settings/ を合わせる。
 */
export const SETTINGS_SECTIONS: SettingsSection[] = [
    { href: '/settings/profile', label: 'プロファイル' },
    { href: '/settings/ai', label: 'AIエンジン' },
    { href: '/settings/usage', label: 'AI の使用状況' },
    { href: '/settings/mail', label: 'メール取り込み' },
    { href: '/settings/backup', label: 'バックアップ' },
];

/** 目次で節名の下に添える現在値。tone は節の状態の色（既定は注記色） */
export interface SettingsNavValue {
    text: string;
    tone?: 'normal' | 'match' | 'check' | 'conflict';
}

/**
 * href → 現在値。1節=1ページなので、開いているページに届く props で分かる節しか値を持てない。
 * 分からない節はキーごと省く（節名だけを出し、値を推測しない）。
 */
export type SettingsNavValues = Partial<Record<string, SettingsNavValue>>;
