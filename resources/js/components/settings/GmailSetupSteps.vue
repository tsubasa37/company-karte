<script setup lang="ts">
import { useClipboardCopy } from '@/composables/useClipboardCopy';
import type { GmailStatus } from '@/types/mail';

// Google Cloud の各画面へ直接飛ぶ。検索して探す手間を無くすため、機能ごとのURLを持つ
const CONSOLE = {
    gmailApi: 'https://console.cloud.google.com/apis/library/gmail.googleapis.com',
    consent: 'https://console.cloud.google.com/apis/credentials/consent',
    credentials: 'https://console.cloud.google.com/apis/credentials',
} as const;

const props = defineProps<{ gmail: GmailStatus }>();

// コピー失敗（権限拒否・非対応）は握り潰さず画面に出す
const { copiedKey, copyError, copy } = useClipboardCopy();
const copyRedirectUri = () => copy(props.gmail.redirectUri, 'redirect-uri');

/** 手順の外部リンクは見た目が同じなので1か所で持つ */
const LINK = 'ml-2 text-[12px] text-ink underline underline-offset-[3px] hover:text-conflict';
</script>

<template>
    <ol class="flex list-none flex-col gap-3 pl-0 text-[12px] leading-5 text-ink">
        <li>
            <span class="font-semibold">1. Gmail API を有効にする</span>
            <a :href="CONSOLE.gmailApi" target="_blank" rel="noopener noreferrer" :class="LINK">APIライブラリを開く ↗</a>
            <p class="mt-0.5 text-ink-2">初回はプロジェクトの作成を求められます（名前は任意）。</p>
        </li>

        <li>
            <span class="font-semibold">2. 同意画面を作り、公開ステータスを「本番」にする</span>
            <a :href="CONSOLE.consent" target="_blank" rel="noopener noreferrer" :class="LINK">OAuth同意画面を開く ↗</a>
            <p class="mt-1 border-l-[3px] border-check bg-check-bg px-2.5 py-1.5 text-check-fg">
                <span class="font-semibold">「テスト」のままだと7日で連携が切れます。</span>
                「アプリを公開」を押して「本番」にしてください。利用者が本人だけなら審査は不要です。
            </p>
        </li>

        <li>
            <span class="font-semibold">3. リダイレクトURIを控える</span>
            <p class="mt-0.5 text-ink-2">次の手順で貼るので、先にコピーしておきます。</p>
            <div class="mt-1.5 flex items-center gap-2">
                <code
                    class="flex-1 overflow-hidden text-ellipsis whitespace-nowrap border border-rule-input bg-white px-2 py-1 font-mono text-[11px]">{{ gmail.redirectUri }}</code>
                <button type="button"
                    class="shrink-0 rounded-[2px] border border-rule-input bg-white btn-sm hover:bg-bench"
                    @click="copyRedirectUri">{{ copiedKey === 'redirect-uri' ? 'コピーしました' : 'コピー' }}</button>
            </div>
            <p v-if="copyError" class="mt-1 text-[11px] text-conflict">
                コピーできませんでした。上の値を選択して手でコピーしてください。
            </p>
        </li>

        <li>
            <span class="font-semibold">4. OAuthクライアントを作成する</span>
            <a :href="CONSOLE.credentials" target="_blank" rel="noopener noreferrer" :class="LINK">認証情報を開く ↗</a>
            <p class="mt-0.5 text-ink-2">
                「認証情報を作成」→「OAuth クライアント ID」→ 種類は<span class="font-semibold">ウェブ アプリケーション</span>。
                「承認済みのリダイレクト URI」に手順3の値をそのまま貼ります。
            </p>
        </li>

        <li>
            <span class="font-semibold">5. 表示されたIDとシークレットを下のフォームに貼る</span>
            <p class="mt-0.5 text-ink-2">保存すると暗号化して保存されます。再起動は不要です。</p>
        </li>
    </ol>
</template>
