/** 設定画面のバックアップ状態（BuildBackupStatusAction の返り値） */
export interface BackupStatusData {
    count: number;
    latestAt: string | null;
    totalBytes: number;
    /** 最終バックアップが7日以上前 or 0件（判定はPHP側で確定済み） */
    stale: boolean;
    /** 命名規約（database-Ymd-His.sqlite）に合致しない形式外ファイルの件数（バックアップとして扱わない） */
    unrecognizedCount: number;
}
