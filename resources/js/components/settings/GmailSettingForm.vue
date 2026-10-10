<script setup lang="ts">
import type { GmailStatus } from '@/types/mail';
import { useForm } from '@inertiajs/vue3';
import { useId } from 'vue';

const props = defineProps<{ gmail: GmailStatus }>();

const uid = useId();
const fid = (key: string) => `${uid}-${key}`;

// シークレットは画面に返らないため常に空で始まる。空のまま保存すれば既存の値が据え置かれる
const form = useForm({
    google_client_id: props.gmail.clientId ?? '',
    google_client_secret: '',
    allowed_domains: props.gmail.allowedDomainsText,
    mask_words: props.gmail.maskWordsText,
});

const save = () => form.put('/settings/mail', { preserveScroll: true });

const LABEL = 'mb-[5px] block text-[11px] leading-4 text-ink-3';
const ERROR = 'mt-1 text-[11px] text-conflict';
</script>

<template>
    <form @submit.prevent="save">
        <!-- ID とシークレットは短いので2列。長い説明の付く2つは全幅で1行に収める -->
        <div class="grid gap-3.5 sm:grid-cols-2">
            <div>
                <label :for="fid('client-id')" :class="LABEL">クライアントID</label>
                <input :id="fid('client-id')" v-model="form.google_client_id" type="text"
                    placeholder="....apps.googleusercontent.com" class="w-full text-[13px]" />
                <p v-if="form.errors.google_client_id" :class="ERROR">{{ form.errors.google_client_id }}</p>
            </div>

            <div>
                <label :for="fid('client-secret')" :class="LABEL">
                    クライアントシークレット
                    <span v-if="gmail.hasClientSecret" class="text-ink-4">— 登録済み。変更するときだけ入力</span>
                </label>
                <input :id="fid('client-secret')" v-model="form.google_client_secret" type="password"
                    autocomplete="off" :placeholder="gmail.hasClientSecret ? '（変更しない場合は空のまま）' : 'GOCSPX-...'"
                    class="w-full text-[13px]" />
                <p v-if="form.errors.google_client_secret" :class="ERROR">
                    {{ form.errors.google_client_secret }}
                </p>
            </div>

            <div class="sm:col-span-2">
                <label :for="fid('domains')" :class="LABEL">
                    取り込む差出人ドメイン（カンマ区切り）— 未設定なら1通も取り込みません
                </label>
                <input :id="fid('domains')" v-model="form.allowed_domains" type="text" placeholder="bizreach.jp,doda.jp"
                    class="w-full text-[13px]" />
                <p v-if="form.errors.allowed_domains" :class="ERROR">{{ form.errors.allowed_domains }}</p>
            </div>

            <div class="sm:col-span-2">
                <label :for="fid('mask')" :class="LABEL">
                    AIに渡す前に伏せる語（カンマ区切り）— メールアドレスと電話番号は指定なしでも伏せます
                </label>
                <input :id="fid('mask')" v-model="form.mask_words" type="text" placeholder="山田太郎,山田"
                    class="w-full text-[13px]" />
                <p v-if="form.errors.mask_words" :class="ERROR">{{ form.errors.mask_words }}</p>
            </div>
        </div>

        <div class="mt-3.5 flex items-center gap-3">
            <button type="submit" :disabled="form.processing"
                class="rounded-[2px] bg-ink btn-md font-medium text-paper disabled:opacity-50">保存</button>
            <span v-if="form.recentlySuccessful" class="text-[12px] text-match-fg">保存しました</span>
        </div>
    </form>
</template>
