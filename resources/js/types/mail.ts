import type { ScheduleEvent } from '@/types/schedule';

/** 日時候補（複数提示されたときは予定を作らず、これを画面に出す） */
export interface MailEventCandidate {
    starts_at: string;
    kind: string;
    title: string;
    place: string;
}

/** メール取り込み1件の履歴 */
export interface MailIngestRow {
    id: number;
    subject: string;
    fromDomain: string;
    receivedAt: string;
    mailKind: string;
    mailKindLabel: string;
    summary: string | null;
    companyId: number | null;
    companyName: string | null;
    sourceId: number | null;
    /** 自動作成できた予定（作れなかった場合は null で eventCandidates に候補が入る） */
    scheduleEvent: ScheduleEvent | null;
    eventCandidates: MailEventCandidate[];
    reviewed: boolean;
    /** 取り込みに失敗したか（AI を通した後の登録失敗。理由は error）。やり直しは行のボタンから */
    failed: boolean;
    error: string | null;
    /** 取り消せる対象が残っているか */
    revertible: boolean;
}

/** Gmail からの取得処理そのものの直近の失敗（成功すると消える） */
export interface MailFetchError {
    message: string;
    at: string | null;
}

/** 設定画面に出す Gmail 連携の状態。秘密の値は返らず「設定済みか」だけが来る */
export interface GmailStatus {
    /** 貼り直しの目印として表示する（シークレットは返らない） */
    clientId: string | null;
    hasClientSecret: boolean;
    /** クライアントIDとシークレットの両方が登録済みか */
    configured: boolean;
    connected: boolean;
    /** 取り込み方法。false（手動）の間は15分おきの取得が1回も走らない */
    autoFetch: boolean;
    email: string | null;
    lastFetchedAt: string | null;
    /** 直近の取得失敗（成功すると消える）。スケジューラの失敗を画面から分かるようにする */
    lastFetchError: string | null;
    lastFetchErrorAt: string | null;
    /** Google Cloud 側に登録してもらうURL。アプリ側で生成した値 */
    redirectUri: string;
    allowedDomains: string[];
    /** フォームの初期値に使う生テキスト（カンマ区切り） */
    allowedDomainsText: string;
    maskWordsText: string;
    windowDays: number;
    maxMessages: number;
}

/** 接続テストの結果（押したときだけ flash で届く） */
export interface GmailTestResult {
    ok: boolean;
    message: string;
}

/** 手動取り込み（「いま取り込む」）の結果（押したときだけ flash で届く） */
export interface MailFetchResult {
    ok: boolean;
    message: string;
}
