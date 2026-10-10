<script setup lang="ts">
import HelpTip from '@/components/HelpTip.vue';
import OnboardingGuide from '@/components/company/OnboardingGuide.vue';
import DashboardAlerts from '@/components/dashboard/DashboardAlerts.vue';
import DashboardOpenIssues from '@/components/dashboard/DashboardOpenIssues.vue';
import DashboardBackupWarning from '@/components/dashboard/DashboardBackupWarning.vue';
import DashboardDeadlines from '@/components/dashboard/DashboardDeadlines.vue';
import DashboardMailUnreviewed from '@/components/dashboard/DashboardMailUnreviewed.vue';
import DashboardFailures from '@/components/dashboard/DashboardFailures.vue';
import DashboardNews from '@/components/dashboard/DashboardNews.vue';
import DashboardRunning from '@/components/dashboard/DashboardRunning.vue';
import DashboardStatusBoard from '@/components/dashboard/DashboardStatusBoard.vue';
import EmptyNote from '@/components/shell/EmptyNote.vue';
import StatusBar from '@/components/shell/StatusBar.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { StatusItem } from '@/types/shell';
import type { AlertBlock, DashboardCompany, ErrorCompanyAlert, FailedJobAlert, FailedRunAlert, StatusGroup } from '@/types/company';
import type { RunningRunAlert, StaleCompany } from '@/types/dashboard';
import { HELP } from '@/constants/help';
import type { DeadlineAlert, MailUnreviewedItem, NewsUncheckedItem, RecentNewsItem } from '@/types/dashboardAlerts';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    statusGroups: StatusGroup[];
    emptyGroups: string | null;
    needsSources: AlertBlock<DashboardCompany>;
    /** 選考の期限（オファー回答期限・次回面接）。近い/過ぎたものだけPHPが選別済み */
    deadlines: AlertBlock<DeadlineAlert>;
    /** メールから自動登録したまま未確認の取り込み（承認ゲートを置かない代わりの気づき手段） */
    mailUnreviewed: AlertBlock<MailUnreviewedItem>;
    /** 取り込み済みニュースの新着（ダッシュボードでは外部通信しない） */
    recentNews: AlertBlock<RecentNewsItem>;
    newsUnchecked: AlertBlock<NewsUncheckedItem>;
    holds: AlertBlock<DashboardCompany & { missing: string | null }>;
    /** 面接で確認する論点が未解消のまま残っている企業 */
    openIssues: AlertBlock<DashboardCompany & { pendingCount: number }>;
    stale: AlertBlock<StaleCompany>;
    profileChanged: AlertBlock<DashboardCompany>;
    laborAlerts: AlertBlock<DashboardCompany & { note: string }>;
    laborCheckedAt: string | null;
    running: RunningRunAlert[];
    failedRuns: FailedRunAlert[];
    errorCompanies: ErrorCompanyAlert[];
    failedJobs: FailedJobAlert[];
    queueStalled: string | null;
    /** 最終バックアップが7日以上前のときの警告文（正常なら null。判定はPHP） */
    backupWarning: string | null;
    hasProfile: boolean;
    companyCount: number;
}>();

// 労基公表リスト突合はPDF取得・解析があるため非同期（queue）で実行される
const laborForm = useForm({});
const runLaborCheck = () => laborForm.post('/labor-check', { preserveScroll: true });

/**
 * 台帳（12節）の通し番号。左余白に出す「順番」と、墨の節のゼブラ（偶数=薄地）に使う。
 * 出ていない節は飛ばして数えるので、番号に欠番が出ない。順序は README 06 の規定どおり。
 */
const nos = computed(() => {
    let n = 0;
    const seq = (visible: boolean) => (visible ? ++n : 0);
    const hasCompanies = props.companyCount > 0;

    return {
        failures: seq(
            props.failedRuns.length > 0 ||
                props.errorCompanies.length > 0 ||
                props.failedJobs.length > 0 ||
                props.queueStalled !== null,
        ),
        deadlines: seq(props.deadlines.total > 0),
        openIssues: seq(hasCompanies && props.openIssues.total > 0),
        mailUnreviewed: seq(props.mailUnreviewed.total > 0),
        running: seq(props.running.length > 0),
        backup: seq(props.backupWarning !== null),
        needsSources: seq(hasCompanies && props.needsSources.total > 0),
        stale: seq(hasCompanies && props.stale.total > 0),
        profileChanged: seq(hasCompanies && props.profileChanged.total > 0),
        holds: seq(hasCompanies && props.holds.total > 0),
        laborAlerts: seq(hasCompanies && props.laborAlerts.total > 0),
        recentNews: seq(hasCompanies && props.recentNews.total > 0),
        newsUnchecked: seq(hasCompanies && props.newsUnchecked.total > 0),
    };
});

/**
 * 台帳に出ている節の数。0 のときだけ上罫と余白を落とす（下のステータス別ボードの太罫と二重線にしない）。
 * v-if で台帳ごと外さないのは、DashboardRunning を常時マウントしたままにしてポーリングの
 * 「0件になったら1回だけ全体リロード」を確実に走らせるため。
 */
const ledgerCount = computed(() => Math.max(0, ...Object.values(nos.value)));

/** 状態バーの「分析済み」。おすすめ度が付いている企業を数え、内訳を ◎○△― の順に並べる */
const REC_SYMBOLS = ['◎', '○', '△', '―'];
const analyzed = computed(() => {
    const counts = new Map<string, number>();
    props.statusGroups.forEach((g) =>
        g.companies.forEach((c) => {
            if (c.recommendationSymbol) counts.set(c.recommendationSymbol, (counts.get(c.recommendationSymbol) ?? 0) + 1);
        }),
    );

    return {
        total: [...counts.values()].reduce((a, b) => a + b, 0),
        note: REC_SYMBOLS.filter((s) => counts.has(s))
            .map((s) => `${s}${counts.get(s)}`)
            .join(' '),
    };
});

/** 状態バー・レールバッジの「要対応」。内訳は台帳1番の節と同じ集合 */
const attention = computed(() => {
    const parts = [
        props.failedJobs.length ? `失敗ジョブ${props.failedJobs.length}` : '',
        props.failedRuns.length ? `失敗ラン${props.failedRuns.length}` : '',
        props.errorCompanies.length ? `取得失敗${props.errorCompanies.length}` : '',
        props.queueStalled ? '滞留' : '',
    ].filter(Boolean);

    return {
        count: props.failedJobs.length + props.failedRuns.length + props.errorCompanies.length,
        note: parts.join('・'),
    };
});


const statusItems = computed<StatusItem[]>(() => [
    { label: '登録', value: props.companyCount, note: '社' },
    { label: '分析済み', value: analyzed.value.total, note: analyzed.value.note || undefined },
    {
        label: '要対応',
        value: attention.value.count,
        note: attention.value.note || undefined,
        tone: attention.value.count > 0 || props.queueStalled ? 'conflict' : 'normal',
    },
    {
        label: '期限が近い',
        value: props.deadlines.total,
        note: '件',
        // 期限切れが1件でもあれば異常、まだ間に合うなら要確認
        tone: props.deadlines.items.some((d) => d.overdue) ? 'conflict' : props.deadlines.total > 0 ? 'check' : 'normal',
    },
    {
        label: '未確認のメール',
        value: props.mailUnreviewed.total,
        note: '通',
        tone: props.mailUnreviewed.total > 0 ? 'check' : 'normal',
    },
]);

// レールのバッジは props から一意に出せるものだけ（予定表・比較の件数は持っていないので出さない）
const navBadges = computed(() => ({
    '/': attention.value.count > 0 ? { text: String(attention.value.count), tone: 'danger' as const } : '',
    '/companies': props.companyCount || '',
    '/mails': props.mailUnreviewed.total > 0 ? { text: String(props.mailUnreviewed.total), tone: 'warn' as const } : '',
}));
</script>

<template>
    <Head title="ダッシュボード" />
    <AppLayout rail :nav-badges="navBadges">
        <template #status>
            <StatusBar :items="statusItems" />
        </template>

        <template #rail>
            <p class="text-[10px] tracking-[.1em] text-rail-muted">毎回ここから</p>
            <p class="mt-[6px] text-[11px] leading-[18px] text-rail-fg">
                上から順に消していけば、<br />次にやることを探さずに済みます。
            </p>
        </template>

        <div class="flex flex-wrap items-baseline justify-between gap-3 px-5 pt-[18px] pb-[14px]">
            <h1 class="font-serif text-[24px] font-semibold text-ink">今日やること</h1>
            <div class="flex flex-wrap items-center gap-[10px]">
                <span v-if="laborCheckedAt" class="font-mono text-[11px] tabular-nums text-ink-3">労基リスト突合: {{ laborCheckedAt }}</span>
                <button @click="runLaborCheck" :disabled="laborForm.processing"
                    class="rounded-[2px] border border-rule-input bg-white btn-sm text-ink hover:bg-bench disabled:opacity-50">
                    労基公表リストと突合
                </button>
                <HelpTip :help="HELP.laborCheck" />
                <Link href="/companies"
                    class="rounded-[2px] border border-rule-input bg-white btn-sm text-ink hover:bg-bench">
                    企業一覧へ
                </Link>
            </div>
        </div>
        <p v-if="laborForm.errors.labor"
            class="mx-5 mt-[10px] border-l-[3px] border-l-conflict bg-conflict-bg px-[11px] py-[7px] text-[12px] text-conflict">
            {{ laborForm.errors.labor }}
        </p>
        <p v-if="laborForm.recentlySuccessful"
            class="mx-5 mt-[10px] border-l-[3px] border-l-ink bg-surface px-[11px] py-[7px] text-[12px] text-ink">
            突合を開始しました（queue実行）。1分ほどしてからこのページを開き直すと結果が反映されます。
        </p>

        <!-- 初回（企業ゼロ or プロフィール未設定）は導線だけを見せる -->
        <div v-if="companyCount === 0 || !hasProfile" class="mt-4 px-5">
            <OnboardingGuide :has-profile="hasProfile" />
        </div>

        <!-- 台帳: 12節を1本の罫線リストにする（左余白の順番と重み記号だけで区別する） -->
        <div :class="ledgerCount > 0 ? 'mt-4 border-t border-t-rule-input' : ''">
            <DashboardFailures :failed-runs="failedRuns" :error-companies="errorCompanies" :failed-jobs="failedJobs"
                :queue-stalled="queueStalled" :no="nos.failures" />
            <!-- 期限は失敗の次に強い（返事の期日を逃すと取り返せない） -->
            <DashboardDeadlines :deadlines="deadlines" :no="nos.deadlines" />
            <!-- 面接までに潰す論点。期限と同じく「予定に効く」ので上に置く -->
            <DashboardOpenIssues v-if="companyCount > 0" :open-issues="openIssues" :no="nos.openIssues" />
            <DashboardMailUnreviewed :mail-unreviewed="mailUnreviewed" :no="nos.mailUnreviewed" />
            <!-- 実行中は自前でポーリングする（running が0件になったら止まる） -->
            <DashboardRunning :running="running" :no="nos.running" />
            <DashboardBackupWarning :warning="backupWarning" :no="nos.backup" />
            <template v-if="companyCount > 0">
                <DashboardAlerts :needs-sources="needsSources" :holds="holds" :stale="stale"
                    :profile-changed="profileChanged" :labor-alerts="laborAlerts" :nos="nos" />
                <DashboardNews :recent-news="recentNews" :news-unchecked="newsUnchecked" :nos="nos" />
            </template>
        </div>

        <DashboardStatusBoard v-if="companyCount > 0" :groups="statusGroups" :empty-groups="emptyGroups" />
        <EmptyNote v-else class="bg-surface">
            <Link href="/companies" class="inline-block rounded-[2px] bg-ink btn-md font-medium text-paper">
                企業を追加する
            </Link>
        </EmptyNote>
    </AppLayout>
</template>
