/**
 * 取り込んだ材料の横断検索の型。
 * 抜粋（前後の文字数・ヒット箇所の切り出し）は PHP（SearchDocumentsAction）が確定済みで、
 * 画面では本文を加工しない。
 */

/** ヒット箇所の切り出し（before / match / after の3片で1箇所） */
export interface Snippet {
    before: string;
    match: string;
    after: string;
}

export interface SearchHit {
    /** source: 取り込んだソース / review: 分割済みの口コミ / issue: 追跡中の論点 / note: 選考メモ */
    kind: 'source' | 'review' | 'issue' | 'note';
    kindLabel: string;
    companyId: number;
    /** 遷移先の id（口コミは元ソース、論点・選考メモは企業） */
    id: number;
    title: string | null;
    snippets: Snippet[];
}

export interface SearchGroup {
    companyId: number;
    companyName: string;
    hits: SearchHit[];
    count: number;
}
