<script setup lang="ts">
import type { CompanySort, StatusOption } from '@/composables/useCompanyFilter';

defineProps<{ statusOptions: StatusOption[]; favoriteCount: number; shownCount: number; totalCount: number }>();

const query = defineModel<string>('query', { required: true });
const status = defineModel<string | null>('status', { required: true });
const sort = defineModel<CompanySort>('sort', { required: true });
const favoriteOnly = defineModel<boolean>('favoriteOnly', { required: true });

// セグメントは1本の枠で囲み、区切りは右罫。選択中だけ墨ベタ（区切りも墨にして継ぎ目を消す）
const segCls = (active: boolean) =>
    active ? 'border-ink bg-ink text-paper' : 'border-rule text-ink-2 hover:bg-bench';
</script>

<template>
    <!-- 作業台の帯。選択が1件でも入ると同じ高さの操作バー（CompanySelectionActions）に差し替わる -->
    <div class="flex flex-wrap items-center gap-3 border-b border-rule-input bg-bench px-5 py-2">
        <div class="relative">
            <input v-model="query" type="search" placeholder="企業名・ポジションで検索"
                aria-label="企業名・ポジションで検索" class="w-60 py-[5px] pr-7 pl-2 text-[12px]" />
        </div>

        <div class="flex items-stretch overflow-hidden rounded-[2px] border border-rule-input bg-white">
            <button type="button" @click="status = null" class="border-r px-[10px] py-1 text-[12px]"
                :class="segCls(status === null)" :aria-pressed="status === null">
                全て <span class="font-mono tabular-nums opacity-[.65]">{{ totalCount }}</span>
            </button>
            <button v-for="o in statusOptions" :key="o.value" type="button" @click="status = o.value"
                class="border-r px-[10px] py-1 text-[12px] last:border-r-0" :class="segCls(status === o.value)"
                :aria-pressed="status === o.value">
                {{ o.label }} <span class="font-mono tabular-nums opacity-[.65]">{{ o.count }}</span>
            </button>
        </div>

        <!-- ★はステータスと独立の絞り込み（併用できる）なのでトグル式 -->
        <button type="button" @click="favoriteOnly = !favoriteOnly"
            class="rounded-[2px] border px-[9px] py-1 text-[12px]"
            :class="favoriteOnly ? 'border-ink bg-ink text-paper' : 'border-rule-input bg-white text-ink-2 hover:border-ink'"
            :aria-pressed="favoriteOnly">
            ★お気に入りだけ <span class="font-mono tabular-nums opacity-[.65]">{{ favoriteCount }}</span>
        </button>

        <label class="ml-auto flex items-center gap-[5px] text-[11px] text-ink-3">
            並び順
            <select v-model="sort" class="py-1 pr-7 pl-[7px] text-[12px]">
                <option value="recommendation">おすすめ度が良い順</option>
                <option value="updated">更新が新しい順</option>
            </select>
        </label>
        <span class="font-mono text-[12px] tabular-nums text-ink-3" :title="`表示 ${shownCount}社 / 全 ${totalCount}社`">
            {{ shownCount }} / {{ totalCount }}
        </span>
    </div>
</template>
