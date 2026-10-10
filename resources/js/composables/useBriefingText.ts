import type { Briefing } from '@/types/company';

/**
 * ブリーフィングを面接に持ち出すためのプレーンテキスト整形（メモアプリへ貼る想定）。
 *
 * 生成日時を先頭に必ず入れる。貼り付けた先では日時が分からず、古い内容を最新の要約と
 * 誤読するため（Markdown エクスポートの ExportKarteMarkdownAction と同じ理由）。
 */
export function briefingToText(briefing: Briefing): string {
    if (briefing.data === null) {
        throw new Error('ブリーフィングが未生成のためコピーできません');
    }

    const d = briefing.data;
    const lines = [`面接前ブリーフィング（生成: ${briefing.generatedAt ?? '不明'}）`, ''];

    const groups: [string, string[]][] = [
        ['要点', d.summary_lines],
        ['未解決の論点', d.open_issues],
        ['逆質問トップ5', d.top_questions],
        ['突っ込まれそうな点と備え', d.weak_points],
    ];
    for (const [title, items] of groups) {
        if (items.length === 0) continue;
        lines.push(`■ ${title}`, ...items.map((x) => `- ${x}`), '');
    }
    if (d.note) {
        lines.push(d.note);
    }

    return lines.join('\n');
}
