import type { P6Questions } from '@/types/karte';

/** 逆質問の優先度ラベル（◎○△はおすすめ度と衝突するため文字で示す） */
export const PRIORITY_LABEL: Record<string, string> = {
    high: '必ず聞く',
    mid: '余裕があれば',
    low: '時間が余れば',
};

export const ASK_TO_LABEL: Record<string, string> = {
    hr: '人事',
    engineer: '現場エンジニア',
    exec: '役員',
    any: 'どちらでも',
};

/** 面接に持ち出すためのプレーンテキスト整形（メモアプリへ貼る想定） */
export function questionsToText(questions: P6Questions['questions']): string {
    return questions
        .map((q, i) => {
            const lines = [
                `${i + 1}. [${PRIORITY_LABEL[q.priority] ?? q.priority}] ${q.question}`,
                `   確認: ${q.targets} / 相手: ${ASK_TO_LABEL[q.ask_to] ?? q.ask_to}`,
            ];
            if (q.casual_version) {
                lines.push(`   カジュアル版: ${q.casual_version}`);
            }

            return lines.join('\n');
        })
        .join('\n');
}
