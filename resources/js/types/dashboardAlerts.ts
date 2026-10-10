/**
 * ダッシュボードの「選考の期限」「ニュース」ブロックの型
 * （BuildDashboardAction の返り値と対応させる）。
 * types/company.ts / types/dashboard.ts が同時編集中のため追加分は本ファイルに定義する。
 */
import type { DashboardCompany } from './company';

/** メールから自動登録したまま未確認の取り込み1件 */
export interface MailUnreviewedItem {
    id: number;
    subject: string;
    kindLabel: string;
    companyId: number | null;
    companyName: string | null;
    receivedAt: string;
}

/** 選考の期限アラート1件。文言（あと何日 / 今日 / 過ぎている）はPHPで確定済み */
export interface DeadlineAlert extends DashboardCompany {
    /** offer = オファー回答期限 / interview = 次回面接 */
    kind: 'offer' | 'interview';
    /** 期限を過ぎているか（強調表示の切り替えにのみ使う） */
    overdue: boolean;
    label: string;
}

/** 直近に取り込んだニュースソース1件 */
export interface RecentNewsItem extends DashboardCompany {
    sourceId: number;
    /** クリップ時のページタイトル。未保存（手貼り）なら本文の冒頭 */
    title: string;
    /** 記事URLのホスト名（URLが無いソースは null） */
    host: string | null;
    ingestedAt: string;
}

/** ニュース確認を促す企業1件 */
export interface NewsUncheckedItem extends DashboardCompany {
    statusLabel: string;
    label: string;
}
