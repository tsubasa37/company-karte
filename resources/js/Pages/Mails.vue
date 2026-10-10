<script setup lang="ts">
import MailIngestItem from '@/components/mail/MailIngestItem.vue';
import EmptyNote from '@/components/shell/EmptyNote.vue';
import StatusBar from '@/components/shell/StatusBar.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { MailFetchError, MailIngestRow } from '@/types/mail';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    ingests: MailIngestRow[];
    unreviewedCount: number;
    /** 取り込みに失敗したまま残っている件数（判定は PHP） */
    failedCount: number;
    /** 取得処理そのものの直近の失敗（成功すると消える） */
    fetchError: MailFetchError | null;
    /** 取り込み方法。false（手動）の間は15分おきの取得が1回も走らない */
    autoFetch: boolean;
    /** 取り消しで消さずに残したものの説明。取り消しを押したときだけ flash で届く */
    flash: { mailRevert: string[] | null };
}>();

// まとめてやり直しは件数ぶん AI を使うので、押す前に件数を出して確認する
const retryAllFailed = () => {
    if (!confirm(`失敗した ${props.failedCount} 件をもう一度取り込みます（AIを${props.failedCount}回使います）。よろしいですか？`)) return;
    router.post('/mails/retry-failed', {}, { preserveScroll: true });
};

// 状態バー・レールの数字は、いま画面に出ている取り込み履歴から数えるだけ（props は増やさない）
const scheduledCount = computed(() => props.ingests.filter((i) => i.scheduleEvent !== null).length);
// 「返信待ち」は候補が複数で予定を作らなかったもの＝画面に候補一覧が出ている行
const pendingCount = computed(
    () => props.ingests.filter((i) => i.scheduleEvent === null && i.eventCandidates.length > 0).length,
);

// 履歴は受信の新しい順（MailIngest::recent）。受信日時は 'Y-m-d H:i' 形式で来る
const latest = computed(() => props.ingests[0]?.receivedAt ?? null);

const tone = (count: number): 'check' | 'normal' => (count > 0 ? 'check' : 'normal');

const status = computed(() => [
    { label: '取り込み', value: props.ingests.length, note: '通' },
    { label: '未確認', value: props.unreviewedCount, note: '通', tone: tone(props.unreviewedCount) },
    { label: '予定を作成', value: scheduledCount.value, note: '件' },
    { label: '返信待ち', value: pendingCount.value, note: '件（候補が複数）', tone: tone(pendingCount.value) },
    {
        label: '最終受信',
        value: latest.value ? latest.value.slice(11) : '—',
        note: latest.value ? `${latest.value.slice(5, 10).replace('-', '/')} 受信` : '取り込みなし',
    },
]);

// 未確認が残っているときだけレールのナビに件数を出す
const navBadges = computed(() =>
    props.unreviewedCount ? { '/mails': { text: String(props.unreviewedCount), tone: 'warn' as const } } : {},
);
</script>

<template>
    <Head title="メール取り込み" />
    <AppLayout rail :nav-badges="navBadges">
        <template #status><StatusBar :items="status" /></template>

        <template #rail>
            <p class="text-[10px] tracking-[.1em] text-rail-muted">取り込みの範囲</p>
            <p class="mt-[6px] text-[11px] leading-[18px] text-rail-fg">
                許可した差出人ドメインのみ<br />
                {{ autoFetch ? '直近の受信分を15分おきに取得' : '設定画面のボタンを押したときだけ取得' }}<br />
                1回あたりの通数に上限あり
            </p>
            <p class="mt-2 text-[11px] leading-[18px] text-rail-fg">読み取り専用。送信・既読化・削除はしません。</p>
        </template>

        <section class="px-5 pt-[18px]">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h1 class="font-serif text-[24px] leading-[1.3] font-semibold text-ink">メール取り込み</h1>
                <Link href="/settings/mail"
                    class="text-[12px] text-ink-2 underline underline-offset-[3px] hover:text-conflict">Gmail連携の設定</Link>
            </div>

            <!-- 取得処理の失敗（スケジューラはログにしか出ないため、ここで気づけるようにする） -->
            <p v-if="fetchError" role="alert"
                class="mt-[10px] border-l-[3px] border-conflict bg-conflict-bg px-3 py-2 text-[12px] leading-5 text-conflict-fg">
                Gmail からの取得が失敗しています<template v-if="fetchError.at">（{{ fetchError.at }}）</template>: {{ fetchError.message }}
                <Link href="/settings/mail" class="ml-1 underline underline-offset-[3px] hover:text-conflict">設定を確認する</Link>
            </p>

            <!-- 取り消しが一部だけ効いた場合。何が残ったかを出す（黙って一部だけ消さない） -->
            <div v-if="flash.mailRevert?.length" role="status"
                class="mt-[10px] border-l-[3px] border-check bg-check-bg px-3 py-2 text-[12px] leading-5 text-check-fg">
                <p class="font-semibold">取り消しましたが、次のものは残しています。</p>
                <ul class="mt-1 list-disc pl-[18px]">
                    <li v-for="(note, i) in flash.mailRevert" :key="i">{{ note }}</li>
                </ul>
            </div>

            <p v-if="failedCount"
                class="mt-[10px] flex flex-wrap items-center gap-3 border-l-[3px] border-conflict bg-conflict-bg px-3 py-2 text-[12px] leading-5 text-conflict-fg">
                <span>取り込みに失敗したメールが {{ failedCount }} 件あります。失敗したメールは次回の自動取得で再処理しません。</span>
                <button type="button" class="rounded-[2px] border border-conflict-border bg-white btn-sm text-conflict hover:bg-conflict-bg"
                    @click="retryAllFailed">失敗した {{ failedCount }} 件をまとめてやり直す</button>
            </p>

            <p class="mt-[10px] max-w-[64rem] text-[12px] leading-5 text-ink-2">
                許可した差出人ドメインの転職メールを{{ autoFetch ? '15分おきに' : '「いま取り込む」を押したときだけ' }}取り込み、企業と予定を自動登録します。
                <b class="font-semibold">返信はGmailで自分で行ってください</b>（このアプリはメールを送信・既読化・削除しません）。
                自動登録の取り違えに気づいたら「取り込みを取り消す」で作られたものを削除できます。
            </p>

            <p v-if="unreviewedCount"
                class="mt-[10px] border-l-[3px] border-check bg-check-bg px-3 py-2 text-[12px] leading-5 text-check-fg">
                未確認の取り込みが {{ unreviewedCount }} 件あります。内容を見て「確認した」を押すと消えます。
            </p>
        </section>

        <ul class="mt-4 border-t border-rule">
            <MailIngestItem v-for="ingest in ingests" :key="ingest.id" :ingest="ingest" />
            <EmptyNote v-if="!ingests.length" as="li">
                取り込んだメールはまだありません。設定画面でGmailと連携し、取り込む差出人ドメインを登録してください。
            </EmptyNote>
        </ul>
    </AppLayout>
</template>
