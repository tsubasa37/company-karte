<script setup lang="ts">
// しょくばらぼ（職場情報総合サイト）は自動取得しない（一括巡回・スクレイピングになるため）。
// 代わりに「どのページの何の数値をどのキーで入れるか」を画面に明示し、手入力に寄せる。
const KEY_EXAMPLES = [
    { key: '月平均所定外労働時間', unit: '時間' },
    { key: '平均継続勤務年数', unit: '年' },
    { key: '有給休暇取得率', unit: '%' },
    { key: '育児休業取得率（女性）', unit: '%' },
    { key: '育児休業取得率（男性）', unit: '%' },
    { key: '女性管理職割合', unit: '%' },
    { key: '新卒3年後定着率', unit: '%' },
];
</script>

<template>
    <details class="mb-3 border border-rule bg-bench px-3 py-[9px] text-[12px] leading-5 text-ink-2">
        <summary class="cursor-pointer font-serif text-[13px] font-semibold text-ink">
            しょくばらぼの数値を手入力する手順（自動取得はしません）
        </summary>
        <ol class="mt-2 ml-4 list-decimal space-y-1">
            <li>
                <a href="https://shokuba.mhlw.go.jp/" target="_blank" rel="noopener noreferrer"
                    class="text-ink underline underline-offset-[3px] hover:text-conflict">職場情報総合サイト（しょくばらぼ）</a>
                で企業名を検索し、その企業のページを開く
            </li>
            <li>「働き方・休み方」「女性の活躍」等の公表区分から、下の項目キーに対応する数値を読む</li>
            <li>下の「数値を追加」で 出典=しょくばらぼ / 項目 / 値 / 単位 / 年度 を入力する</li>
            <li>出典URLに企業ページのURLを入れると、あとから根拠を辿れる</li>
        </ol>
        <p class="mt-2">項目キーの例（この表記で入れると比較時に揃う）:</p>
        <ul class="mt-1 ml-4 list-disc">
            <li v-for="e in KEY_EXAMPLES" :key="e.key">
                <span class="text-ink">{{ e.key }}</span>（単位: {{ e.unit }}）
            </li>
        </ul>
        <p class="mt-2 text-ink-3">
            自動取得があるのは EDINET（上場企業の有報: 平均年間給与・平均年齢・平均勤続年数・従業員数）と
            gBizINFO（認定・補助金・調達）だけです。
        </p>
    </details>
</template>
