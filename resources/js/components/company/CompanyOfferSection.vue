<script setup lang="ts">
import type { CompanyInfoForm } from '@/types/company';
import { useId } from 'vue';

// form は親（TabInfo）の useForm をそのまま受け取り、入力欄だけを分担する
defineProps<{ form: CompanyInfoForm }>();

const uid = useId();
const fid = (key: string) => `${uid}-${key}`;
</script>

<template>
    <section>
        <h3 class="border-b-2 border-ink pb-[5px] font-serif text-[19px] font-semibold text-ink">
            オファー条件（提示されたら入力 → 比較ビューで現年収と差を表示）
        </h3>
        <p class="mt-2.5 mb-[14px] text-[12px] leading-5 text-ink-2">
            基本給・固定残業・賞与を入れると、額面の差ではなく実質での比較ができます。
            オファー額と現年収はAIには送信されません。
        </p>
        <div class="grid gap-[14px] sm:grid-cols-2">
            <div>
                <label :for="fid('offer-salary')" class="mb-[5px] block text-[11px] leading-4 text-ink-3">オファー年収（万円）</label>
                <input :id="fid('offer-salary')" v-model.number="form.offer_annual_salary" type="number" min="0"
                    class="w-full text-[13px] tabular-nums" placeholder="600" />
            </div>
            <div>
                <label :for="fid('offer-base')" class="mb-[5px] block text-[11px] leading-4 text-ink-3">基本給（月額・円）</label>
                <input :id="fid('offer-base')" v-model.number="form.offer_base_monthly_yen" type="number" min="0"
                    class="w-full text-[13px] tabular-nums" placeholder="450000" />
            </div>
            <div>
                <label :for="fid('offer-overtime')" class="mb-[5px] block text-[11px] leading-[17px] text-ink-3">
                    固定残業時間（時間/月・提示額に含まれる分）
                </label>
                <input :id="fid('offer-overtime')" v-model.number="form.offer_fixed_overtime_hours" type="number" min="0"
                    max="200" class="w-full text-[13px] tabular-nums" placeholder="20" />
            </div>
            <div>
                <label :for="fid('offer-bonus')" class="mb-[5px] block text-[11px] leading-4 text-ink-3">賞与（月数）</label>
                <input :id="fid('offer-bonus')" v-model.number="form.offer_bonus_months" type="number" min="0" max="24"
                    step="0.1" class="w-full text-[13px] tabular-nums" placeholder="2" />
            </div>
            <div class="sm:col-span-2">
                <label :for="fid('offer-notes')" class="mb-[5px] block text-[11px] leading-4 text-ink-3">条件メモ（上記に入らない条件）</label>
                <input :id="fid('offer-notes')" v-model="form.offer_notes" type="text"
                    class="w-full text-[13px]" placeholder="ストックオプション / 住宅手当3万 など" />
            </div>
        </div>
    </section>
</template>
