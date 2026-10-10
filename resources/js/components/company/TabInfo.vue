<script setup lang="ts">
import CompanyDeleteSection from '@/components/company/CompanyDeleteSection.vue';
import CompanyOfferSection from '@/components/company/CompanyOfferSection.vue';
import CompanyScheduleSection from '@/components/company/CompanyScheduleSection.vue';
import CompanySelectionSection from '@/components/company/CompanySelectionSection.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import type { Company, Option } from '@/types/company';
import type { ScheduleEvent } from '@/types/schedule';
import { useForm } from '@inertiajs/vue3';
import { useId } from 'vue';

const props = defineProps<{
    company: Company;
    statusOptions: Option[];
    /** この企業の選考予定（時系列）。登録・編集は /schedule に投げる */
    scheduleEvents: ScheduleEvent[];
    scheduleKindOptions: Option[];
}>();

// label[for] と input[id] の紐付け用。useId でコンポーネント単位に一意化する（id 衝突を防ぐ）
const uid = useId();
const fid = (key: string) => `${uid}-${key}`;

// 日付・日時の入力欄は null を扱えないため空文字にする（保存時は Laravel が空文字を null に戻す）
const form = useForm({
    name: props.company.name,
    listed: props.company.listed,
    position: props.company.position,
    legal_number: props.company.legalNumber,
    status: props.company.status,
    selection_notes: props.company.selectionNotes ?? '',
    offer_deadline: props.company.offerDeadline ?? '',
    offer_annual_salary: props.company.offerAnnualSalary,
    offer_notes: props.company.offerNotes ?? '',
    offer_base_monthly_yen: props.company.offerBaseMonthlyYen ?? null,
    offer_fixed_overtime_hours: props.company.offerFixedOvertimeHours ?? null,
    offer_bonus_months: props.company.offerBonusMonths ?? null,
});
// 保存済みの値から作ったフォームなので、変わっていれば本人が触った未保存の変更
useUnsavedGuard(() => form.isDirty);

const save = () => form.put(`/companies/${props.company.id}`, { preserveScroll: true });
</script>

<template>
    <!-- ルートは単一要素（親の v-show 対象のため） -->
    <div class="space-y-10">
    <form class="space-y-10" @submit.prevent="save">
        <section>
            <h3 class="border-b-2 border-ink pb-[5px] font-serif text-[19px] font-semibold text-ink">基本情報</h3>
            <div class="mt-[14px] grid gap-[14px] sm:grid-cols-2">
                <div>
                    <label :for="fid('name')" class="mb-[5px] block text-[11px] leading-4 text-ink-3">企業名</label>
                    <input :id="fid('name')" v-model="form.name" type="text"
                        class="w-full text-[13px]" />
                    <p v-if="form.errors.name" class="mt-1 text-[11px] text-conflict">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label :for="fid('position')" class="mb-[5px] block text-[11px] leading-4 text-ink-3">ポジション</label>
                    <input :id="fid('position')" v-model="form.position" type="text"
                        class="w-full text-[13px]" placeholder="バックエンドエンジニア" />
                </div>
                <div>
                    <!-- リンクは label の外に置く（label 内のリンクはクリック時に入力欄へフォーカスが飛ぶため） -->
                    <p class="mb-[5px] text-[11px] leading-[17px] text-ink-3">
                        <label :for="fid('legal-number')">法人番号</label>
                        <span class="ml-1">— EDINET / gBizINFO 取得の前提。</span>
                        <a href="https://www.houjin-bangou.nta.go.jp/" target="_blank" rel="noopener noreferrer"
                            class="text-ink underline underline-offset-[3px] hover:text-conflict">
                            国税庁の公表サイトで社名検索
                        </a>
                    </p>
                    <input :id="fid('legal-number')" v-model="form.legal_number" type="text"
                        class="w-full font-mono text-[13px] tabular-nums" placeholder="13桁の数字" />
                    <p v-if="company.edinetCode" class="mt-1 text-[11px] text-ink-3">
                        EDINETコード: <span class="font-mono tabular-nums">{{ company.edinetCode }}</span>（取得済み）
                    </p>
                </div>
                <label :for="fid('listed')" class="flex items-end gap-2 pb-2 text-[13px] text-ink-2">
                    <input :id="fid('listed')" v-model="form.listed" type="checkbox"
                        class="rounded-[2px] border-rule-input" />
                    上場企業
                </label>
            </div>
        </section>

        <CompanySelectionSection :form="form" :status-options="statusOptions" />
        <CompanyOfferSection :form="form" />

        <div class="flex items-center justify-end gap-3">
            <span v-if="form.recentlySuccessful" class="text-[12px] text-match-fg">保存しました</span>
            <button type="submit" :disabled="form.processing"
                class="rounded-[2px] bg-ink btn-md font-medium text-paper disabled:opacity-50">
                保存
            </button>
        </div>
    </form>

    <!-- 予定は独自に送信するため、企業情報のフォームの外に置く（form の入れ子は不正） -->
    <CompanyScheduleSection :company-id="company.id" :events="scheduleEvents"
        :kind-options="scheduleKindOptions" />

    <CompanyDeleteSection :company-id="company.id" :company-name="company.name" />
    </div>
</template>
