<script setup lang="ts">
/**
 * 初回導線（ダッシュボード・企業一覧）と、使い方ページ（/help）の「Chrome拡張の設定」節。
 *
 * 拡張のセットアップ手順をこのファイルに置いたままにしているのは、手順が画面コンポーネントに
 * 揃っていること（README を開かずに済むこと）を OnboardingGuideTokenTest がこのファイルに対して
 * 検証しているため。手順は1箇所だけに持ち、/help からは extensionOnly で同じものを描画する。
 * 流れ・分析結果の読み方・用語は /help が正本なので、ここには短い行動の一覧だけを残す。
 */
import { Link } from '@inertiajs/vue3';

defineProps<{
    /** 初回導線として使うときの、プロファイル設定済みかどうか */
    hasProfile?: boolean;
    /** /help から拡張の手順だけを描くとき（初回導線の本体は出さない） */
    extensionOnly?: boolean;
}>();
</script>

<template>
    <!-- オンボーディング導線: 未設定 or 企業ゼロのとき「次の一手」を示す -->
    <!-- 出るのは初回（プロファイル未設定 or 企業0件）だけなので、既定で開いておく（畳むのは任意） -->
    <details v-if="!extensionOnly" open class="border-b border-rule-input bg-surface px-5 py-[10px]">
        <summary class="cursor-pointer font-serif text-[13px] font-semibold text-ink">
            はじめに ── 企業カルテの作り方
            <span v-if="!hasProfile" class="ml-[10px] font-sans text-[11px] font-normal text-check">
                プロフィール未設定
            </span>
        </summary>
        <ol class="mt-[10px] list-decimal pl-5 text-[12px] leading-[21px] text-ink-2">
            <li>
                <Link href="/settings"
                    class="font-medium text-ink underline underline-offset-[3px] hover:text-conflict">
                    設定でプロフィール（必須/歓迎条件）を登録
                </Link>
                <span v-if="!hasProfile" class="ml-1 border-l-2 border-check pl-[5px] text-check">未設定</span>
                <span v-else class="ml-1 border-l-2 border-match pl-[5px] text-match">設定済み</span>
                <span class="text-ink-3">— おすすめ度の判定基準になります</span>
            </li>
            <li>
                <Link href="/companies"
                    class="font-medium text-ink underline underline-offset-[3px] hover:text-conflict">
                    企業一覧のフォーム
                </Link>から気になる企業を追加
            </li>
            <li>企業ページで求人票・口コミを投入（貼り付け / URL / 拡張で1クリック）</li>
            <li>「分析実行」でカルテ（おすすめ度・主張検証など）を生成</li>
            <li>面接が決まったら、カルテの「逆質問を生成する」ボタンで逆質問を個別生成</li>
        </ol>

        <!-- 拡張のセットアップ手順（下のリスト）と、分析結果の読み方・用語は使い方ページで読む -->
        <p class="mt-[10px] border-t border-rule pt-[9px] text-[12px] leading-5 text-ink-2">
            Chrome拡張（見ているページを1クリックで取り込む）のセットアップ手順、分析結果の読み方、用語は
            <Link href="/help" class="font-medium text-ink underline underline-offset-[3px] hover:text-conflict">
                使い方
            </Link>
            にまとめています。
        </p>
    </details>

    <!-- 拡張のセットアップ手順。README を探さず画面だけで完結させる（A-8）。表示先は /help -->
    <ol v-else class="list-decimal pl-5 text-[12px] leading-5 text-ink-2">
        <li>
            Chrome の拡張機能ページを開く。<span class="text-ink-3">chrome:// はリンクから開けないので、
                次の文字列をコピーしてアドレスバーに貼る</span>
            <code class="mt-1 block w-fit select-all rounded-[2px] bg-bench px-2 py-1 font-mono text-[11px] text-ink">
                chrome://extensions
            </code>
        </li>
        <li>右上の<span class="font-medium text-ink">デベロッパーモード</span>をONにする</li>
        <li>
            「パッケージ化されていない拡張機能を読み込む」を押し、このリポジトリ直下の
            <code class="rounded-[2px] bg-bench px-1 font-mono text-[11px] text-ink">extension/</code> フォルダを選ぶ
        </li>
        <li>
            <code class="rounded-[2px] bg-bench px-1 font-mono text-[11px] text-ink">extension/config.example.js</code> を
            <code class="rounded-[2px] bg-bench px-1 font-mono text-[11px] text-ink">extension/config.js</code> にコピーする
        </li>
        <li>
            <code class="rounded-[2px] bg-bench px-1 font-mono text-[11px] text-ink">config.js</code> の
            <code class="rounded-[2px] bg-bench px-1 font-mono text-[11px] text-ink">TOKEN</code> を、
            <code class="rounded-[2px] bg-bench px-1 font-mono text-[11px] text-ink">.env</code> の
            <code class="rounded-[2px] bg-bench px-1 font-mono text-[11px] text-ink">CLIP_TOKEN</code> と同じ値にする
            <span class="block text-ink-3">
                トークンはこの画面には表示しません。値は <code class="font-mono">.env</code> を直接開いて確認してください
            </span>
        </li>
        <li>
            拡張を更新したときは
            <code class="rounded-[2px] bg-bench px-1 font-mono text-[11px] text-ink">chrome://extensions</code>
            の 🔄 を押すだけ（削除・再導入は不要）
        </li>
    </ol>
</template>
