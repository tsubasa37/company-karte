/**
 * ニュース検索結果（Bing News RSS 由来。link は記事の実URL）。
 * types/company.ts の NewsArticle と基本項目が重複するが、company.ts が
 * 同時編集中のため、取込状態つきの型は本ファイルに定義して News.vue から使う。
 */
export interface NewsArticle {
    title: string;
    link: string;
    source: string;
    publishedAt: string | null;
    /** 取込済みか（同一URLの type=news ソースが存在する） */
    imported: boolean;
    /** 取込済みなら該当 source_documents.id、未取込は null */
    sourceId: number | null;
    /** RSS の概要（タグを剥がして 300 字まで。無ければ空文字） */
    description: string;
    /** 機械判定の結果。未判定は null */
    screening: NewsScreening | null;
}

/** 記事の種類（機械判定の choice。App\Enums\NewsKind） */
export type NewsKind = 'incident' | 'performance' | 'organization' | 'product' | 'hiring' | 'other';

/** 深刻度（しきい値で PHP が確定。App\Enums\NewsSeverity） */
export type NewsSeverity = 'neutral' | 'caution' | 'serious';

/** 記事ごとの機械判定の結果（確率・score の生値は届かない） */
export interface NewsScreening {
    relevant: boolean;
    kind: NewsKind;
    kindLabel: string;
    severity: NewsSeverity;
    severityLabel: string;
}

/** 判定ボタンと状態バー用の集計 */
export interface NewsScreeningStatus {
    /** 判定エンジンが設定済みか */
    enabled: boolean;
    /** いまの検索結果のうち未判定の件数 */
    unscreened: number;
    /** 関連あり かつ 深刻 の件数 */
    seriousCount: number;
}

/** 判定ボタンの結果（flash newsScreen） */
export interface NewsScreenResult {
    ok: boolean;
    message: string;
    screened: number;
}
