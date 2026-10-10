<script setup lang="ts">
import HelpTip from '@/components/HelpTip.vue';
import KarteSkipped from '@/components/karte/KarteSkipped.vue';
import type { P3Rhetoric } from '@/types/karte';
import { computed } from 'vue';
import { HELP } from '@/constants/help';

const props = defineProps<{ p3: P3Rhetoric | null; skippedReason: string | null }>();

/**
 * 0件のときに「何を照合したか」を示すための観点一覧。
 * 検出辞書の正は resources/prompts/p3.txt のタスク1。辞書を増減したらここも揃えること。
 */
const CHECKED_PATTERNS =
    'アットホーム/家族のような、若手が活躍/平均年齢20代、幹部候補/裁量が大きい（丸投げ）、' +
    'やる気次第で昇給、未経験歓迎×高収入、固定残業◯h分を含む/みなし、' +
    'スピード感/変化を楽しめる方、賞与は業績による（実績記載なし）';

const salary = computed(() => props.p3?.salary_breakdown ?? null);

const yen = (v: number) => `${v.toLocaleString()}円`;

/** 求人票から抽出できた記載（数値の記載が無い項目は並べない。旧ランはキー自体が無い） */
const extractedParts = computed<string[]>(() => {
    const s = salary.value;
    if (s === null) return [];

    const parts = [`固定残業: ${s.has_fixed_overtime ? 'あり' : 'なし/不明'}`];
    if (typeof s.fixed_overtime_hours === 'number') parts.push(`記載時間: ${s.fixed_overtime_hours}h`);
    if (typeof s.fixed_overtime_amount_yen === 'number') parts.push(`記載額: ${yen(s.fixed_overtime_amount_yen)}`);
    if (typeof s.monthly_salary_min_yen === 'number') parts.push(`月給(下限): ${yen(s.monthly_salary_min_yen)}`);

    return parts;
});

/**
 * 基本給・実質時給の表示。値は PHP（SalaryBreakdownCalculator）が確定した computed のみを使い、
 * 画面側で計算し直さない。算出できなかった場合・旧ランで未保存の場合も黙って隠さず理由を出す。
 */
const salaryComputedText = computed<{ warn: boolean; text: string } | null>(() => {
    const c = salary.value?.computed;
    if (c === null || c === undefined) {
        return { warn: true, text: '基本給・実質時給: 未保存（PHPでの確定計算より前に実行されたランです。再分析が必要）' };
    }
    if (c.unavailable_reason !== null) {
        return { warn: true, text: `基本給・実質時給: 算出不能 — ${c.unavailable_reason}` };
    }
    if (c.base_salary_yen === null || c.effective_hourly_yen === null) {
        return { warn: true, text: '基本給・実質時給: 未保存（計算結果が保存されていません。再分析が必要）' };
    }

    return {
        warn: false,
        text:
            `基本給: ${yen(c.base_salary_yen)} / 実質時給: ${yen(c.effective_hourly_yen)}` +
            `（月間所定 ${c.monthly_work_hours}h + 固定残業時間で算出）`,
    };
});
</script>

<template>
    <KarteSkipped v-if="skippedReason" id="karte-rhetoric" title="危険サイン・実質給与" :reason="skippedReason" />
    <!-- 総合所見→主張検証→逆質問の距離を縮めるため既定で畳む（ナビから直接開ける） -->
    <details v-else-if="p3" id="karte-rhetoric" class="scroll-mt-24">
        <summary class="flex cursor-pointer flex-wrap items-center gap-2 border-b-2 border-ink pb-[5px] font-serif text-[19px] font-semibold text-ink">
            危険サイン・実質給与
            <HelpTip :help="HELP.rhetoric" />
            <span v-if="p3.rhetoric_flags?.length" class="font-sans text-[12px] font-normal text-check">
                注意表現 {{ p3.rhetoric_flags.length }}件
            </span>
        </summary>
        <div class="mt-[14px]">
            <p class="mb-3 text-[13px] leading-[21px]">
                <span class="text-ink-3">具体性: </span>
                <b class="text-ink">{{ p3.concreteness?.level }}</b>
                <span class="text-ink-2"> — {{ p3.concreteness?.reason }}</span>
            </p>
            <ul v-if="p3.rhetoric_flags?.length" class="mb-[14px] list-none border-t border-rule p-0">
                <li v-for="(f, i) in p3.rhetoric_flags" :key="i"
                    class="border-b border-rule-thin py-[9px] text-[13px] leading-[21px]">
                    <span class="font-serif font-semibold text-ink">「{{ f.phrase }}」</span>
                    <span class="text-ink-2"> → {{ f.concern }}</span>
                </li>
            </ul>
            <!-- 0件を「安全」と読み違えないよう、照合した観点を明示する -->
            <p v-else class="mb-[14px] text-[12px] leading-[20px] text-ink-2">
                チェックした要注意パターンに該当する表現は見つかりませんでした。
                <span class="mt-1 block text-ink-3">照合した観点: {{ CHECKED_PATTERNS }}</span>
            </p>
            <div v-if="salary" class="border border-rule-input bg-surface px-[15px] py-[13px]">
                <div class="font-serif text-[15px] font-semibold text-ink">実質給与分解</div>
                <div class="mt-[5px] text-[13px] tabular-nums text-ink-2">{{ extractedParts.join(' / ') }}</div>
                <div v-if="salaryComputedText" class="mt-[5px] text-[13px] tabular-nums"
                    :class="salaryComputedText.warn ? 'text-check' : 'text-ink'">
                    {{ salaryComputedText.text }}
                </div>
                <div class="mt-[5px] text-[12px] leading-[19px] text-ink-3">{{ salary.note }}</div>
            </div>
        </div>
    </details>
</template>
