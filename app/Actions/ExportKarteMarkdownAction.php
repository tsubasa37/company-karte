<?php

namespace App\Actions;

use App\Enums\VerdictPerspective;
use App\Models\Company;
use App\Services\Ai\VerdictConsensus;
use App\Services\BriefingStaleness;

/**
 * カルテを Obsidian 等へ持ち出すための Markdown を組み立てる。
 *
 * データ取得・表示順（⚠️→🔀→✅→❓、逆質問の優先度順）・おすすめ度の確定は
 * BuildCompanyPageAction が唯一の正規パスなので、ここでは複製せず DI で
 * 同 Action の出力（company / karte / sources）を Markdown に整形するだけにする。
 */
class ExportKarteMarkdownAction
{
    /** P2 判定の見出し（BuildCompanyPageAction の並び順と同じ ⚠️→🔀→✅→❓） */
    private const VERDICT_HEADINGS = [
        'contradict' => '⚠️ 矛盾',
        // 判定者の意見が分かれた主張。材料を足しても解消しないため検証不能とは分けて出す
        VerdictConsensus::SPLIT => '🔀 判定割れ',
        'match' => '✅ 整合',
        'unverifiable' => '❓ 検証不能',
    ];

    /** 必須/歓迎条件の記号（KarteSummary.vue の checkMeta と同一） */
    private const CHECK_SYMBOL = ['met' => '✅', 'unmet' => '⚠️', 'unknown' => '❓'];

    /** NG条件の記号（該当=悪いため専用。KarteSummary.vue の ngMeta と同一） */
    private const NG_SYMBOL = ['hit' => '🚫', 'clear' => '✅', 'unknown' => '❓'];

    /** 逆質問の優先度ラベル（useQuestionsClipboard.ts の PRIORITY_LABEL と同一文言） */
    private const PRIORITY_LABEL = ['high' => '必ず聞く', 'mid' => '余裕があれば', 'low' => '時間が余れば'];

    /** 逆質問の相手ラベル（useQuestionsClipboard.ts の ASK_TO_LABEL と同一文言） */
    private const ASK_TO_LABEL = ['hr' => '人事', 'engineer' => '現場エンジニア', 'exec' => '役員', 'any' => 'どちらでも'];

    public function __construct(
        private readonly BuildCompanyPageAction $page,
        private readonly BriefingStaleness $staleness,
    ) {}

    /**
     * 最新の done ランのカルテを Markdown 文字列にして返す。
     * done ランが無い（未分析の）企業はエラーではなく「まだ出せない」状態なので null。
     */
    public function execute(Company $company): ?string
    {
        $data = $this->page->execute($company);
        $karte = $data['karte'];

        if ($karte === null) {
            return null;
        }

        // skipped は JSON で {} にするため stdClass で渡ってくる（BuildCompanyPageAction 参照）。PHP 内では配列で扱う
        $skipped = (array) $karte['skipped'];

        $lines = [
            "# {$data['company']['name']}",
            '',
            '- おすすめ度: '.($karte['recommendation']['label'] ?? '未判定'),
        ];
        if ($karte['recommendationReason'] !== null) {
            $lines[] = "- 判定の理由: {$karte['recommendationReason']}";
        }
        $lines[] = '- 分析日時: '.($karte['finishedAt'] ?? '不明');
        // 判定が割れなかったカルテは、1人で見た結果と複数人が一致した結果が同じ形になる。
        // 持ち出した後で判断の重みが分からなくならないよう、人数と観点を必ず書く
        $panelNames = array_map(
            fn (VerdictPerspective $p) => $p->label(),
            VerdictPerspective::forPanel($karte['panelSize']),
        );
        $lines[] = "- 裏取り: {$karte['panelSize']}人（".implode(' / ', $panelNames).'）';

        $lines = array_merge(
            $lines,
            // ブリーフィングは「面接直前に読む1ページ」なので、持ち出した Markdown でも先頭に置く
            $this->briefingSection($data['briefing'], $this->staleness->reasonFor($company)),
            $this->section('総合所見', $karte['p5'] ?? null, $skipped['p5'] ?? null, $this->summaryBody(...)),
            $this->verdictSection($karte, $skipped),
            $this->section('危険サイン・実質給与', $karte['p3'] ?? null, $skipped['p3'] ?? null, $this->rhetoricBody(...)),
            $this->section('口コミ要約', $karte['p4'] ?? null, $skipped['p4'] ?? null, $this->reviewsBody(...)),
            // 逆質問は分析に含めず個別生成。未生成のときは黙って落とさず理由を書く
            $this->section(
                '面接での逆質問',
                $karte['p6'] ?? null,
                $skipped['p6'] ?? ($karte['p6'] === null ? '未生成（カルテ画面の「逆質問を生成する」で作成できます）' : null),
                $this->questionsBody(...),
            ),
            $this->sourcesSection($data['sources']),
            ['', '---', '', '出力日時: '.now()->format('Y-m-d H:i')],
        );

        return $this->withoutEmbeddedImages(implode("\n", $lines)."\n");
    }

    /**
     * 画像埋め込み記法 `![...](...)` を打ち消す。
     *
     * 書き出す本文には求人票・口コミ由来の文章と、それを読んだ AI の出力が入る。
     * `![](https://攻撃者/x.png)` を混ぜられると、書き出した .md を Obsidian で開いた瞬間に
     * 外部へリクエストが飛ぶ（閲覧の検知・IPの漏洩）。リンク記法 `[...](...)` は
     * クリックしなければ何も起きないので残し、自動で読み込まれる画像だけを止める。
     *
     * 個々の埋め込み箇所ではなく出力直前の1点で処理するのは、この Action 自身が
     * `![` を出力しないため（見出し・箇条書き・記号のみ）。ここで潰して困る自前の記法が無い。
     */
    private function withoutEmbeddedImages(string $markdown): string
    {
        return str_replace('![', '!\[', $markdown);
    }

    /**
     * 面接前ブリーフィング（未生成なら何も出さない）。
     *
     * 生成日時を必ず併記する。持ち出した Markdown だけを見ると生成時期が分からず、
     * 古い内容を最新のカルテの要約と誤読するため。生成後にカルテが更新されている場合は
     * その旨も出す（判定は BriefingStaleness）。
     *
     * @param  array<string, mixed>  $briefing  BuildCompanyPageAction::briefing() の出力
     * @return array<int, string>
     */
    private function briefingSection(array $briefing, ?string $staleReason): array
    {
        $data = $briefing['data'];

        if ($data === null) {
            return [];
        }

        // 生成日時は生成成功時に必ず入るが、欠けていたら黙って隠さず「不明」と出す
        $lines = ['', '## 🎤 面接前ブリーフィング（生成: '.($briefing['generatedAt'] ?? '不明').'）', ''];

        if ($staleReason !== null) {
            $lines = [...$lines, "⚠️ {$staleReason}", ''];
        }

        $lines = [...$lines, ...array_map(fn ($l) => "- {$l}", $data['summary_lines'] ?? [])];

        $groups = [
            ['未解決の論点', $data['open_issues'] ?? []],
            ['逆質問トップ5', $data['top_questions'] ?? []],
            ['突っ込まれそうな点と備え', $data['weak_points'] ?? []],
        ];
        foreach ($groups as [$title, $items]) {
            if ($items === []) {
                continue;
            }
            $lines = [...$lines, '', "### {$title}", '', ...array_map(fn ($s) => "- {$s}", $items)];
        }

        if (($data['note'] ?? '') !== '') {
            $lines = [...$lines, '', $data['note']];
        }

        return $lines;
    }

    /**
     * 見出し + 本文のセクション。ステップがスキップ済みなら理由を、データが無ければ何も出さない。
     *
     * @param  array<string, mixed>|null  $step
     * @param  callable(array<string, mixed>): array<int, string>  $body
     * @return array<int, string>
     */
    private function section(string $title, ?array $step, ?string $skippedReason, callable $body): array
    {
        if ($step === null) {
            return $skippedReason === null ? [] : ['', "## {$title}", '', "（スキップ: {$skippedReason}）"];
        }

        return ['', "## {$title}", '', ...$body($step)];
    }

    /**
     * @param  array<string, mixed>  $p5
     * @return array<int, string>
     */
    private function summaryBody(array $p5): array
    {
        $lines = array_map(fn ($l) => "- {$l}", $p5['summary_lines'] ?? []);
        if (($p5['appeal'] ?? '') !== '') {
            $lines[] = "- **魅力**: {$p5['appeal']}";
        }
        if (($p5['concern'] ?? '') !== '') {
            $lines[] = "- **懸念**: {$p5['concern']}";
        }

        $checks = [
            ['必須条件', $p5['must_checks'] ?? [], self::CHECK_SYMBOL],
            ['歓迎条件', $p5['want_checks'] ?? [], self::CHECK_SYMBOL],
            ['NG条件（該当したら減点）', $p5['ng_checks'] ?? [], self::NG_SYMBOL],
        ];
        foreach ($checks as [$title, $items, $symbols]) {
            if ($items === []) {
                continue;
            }
            $lines = [...$lines, '', "### {$title}", ''];
            foreach ($items as $c) {
                $symbol = $symbols[$c['status'] ?? ''] ?? '❓';
                $evidence = ($c['evidence'] ?? '') !== '' ? " — {$c['evidence']}" : '';
                $lines[] = "- {$symbol} {$c['condition']}{$evidence}";
            }
        }

        return $lines;
    }

    /**
     * 主張検証。判定別（⚠️→✅→❓）に件数付きで全件列挙する（❓も折りたたまない）。
     *
     * @param  array<string, mixed>  $karte
     * @param  array<string, string>  $skipped
     * @return array<int, string>
     */
    private function verdictSection(array $karte, array $skipped): array
    {
        $counts = $karte['verdictCounts'];
        $split = $counts[VerdictConsensus::SPLIT];
        $title = "主張検証（⚠️矛盾 {$counts['contradict']} / 🔀判定割れ {$split}"
            ." / ✅整合 {$counts['match']} / ❓検証不能 {$counts['unverifiable']}）";

        return $this->section($title, $karte['p2'] ?? null, $skipped['p2'] ?? null, function (array $p2) use ($counts): array {
            $groups = collect($p2['verdicts'] ?? [])->groupBy('verdict');
            $lines = [];
            foreach (self::VERDICT_HEADINGS as $verdict => $heading) {
                $lines = [...$lines, "### {$heading}（{$counts[$verdict]}件）", ''];
                foreach ($groups->get($verdict, collect()) as $v) {
                    $lines[] = "- {$v['claim']}";
                    $lines[] = "    - 出典: {$v['source']} / 突合先: {$v['checked_against']}";
                    $lines[] = "    - 根拠: {$v['rationale']}";
                    // 複数人で判定したランは、票の内訳が無いと「何人中何人の判定か」が書き出しから消える
                    $consensus = $this->consensusLine($v['consensus'] ?? null);
                    if ($consensus !== null) {
                        $lines[] = "    - 合議: {$consensus}";
                    }
                }
                if ($counts[$verdict] === 0) {
                    $lines[] = '- 該当なし';
                }
                $lines[] = '';
            }

            return array_slice($lines, 0, -1);
        });
    }

    /**
     * 判定1件ぶんの合議の内訳（1人で判定したラン・多重化以前のランは null）。
     *
     * @param  mixed  $consensus  VerdictConsensus が付けた合議結果
     */
    private function consensusLine(mixed $consensus): ?string
    {
        if (! is_array($consensus) || ($consensus['panel_size'] ?? 1) <= 1) {
            return null;
        }

        // 観点が違えば突き合わせた材料も違うため、判定だけでなく各自の突合先も残す
        $opinions = collect($consensus['opinions'] ?? [])
            ->map(fn (array $o) => $o['label'].': '.(self::VERDICT_HEADINGS[$o['verdict'] ?? ''] ?? '判定なし')
                .'／突合先: '.($o['checked_against'] !== '' ? $o['checked_against'] : '材料なし'))
            ->implode(' / ');

        if (($consensus['aggregated'] ?? false) !== true) {
            return "{$opinions}（主張との対応が不明なため合議の対象外）";
        }

        // 文言は VerdictConsensus が確定済み。ここでは判定者ごとの内訳を添えるだけ
        return "{$consensus['summary']}（{$opinions}）";
    }

    /**
     * @param  array<string, mixed>  $p3
     * @return array<int, string>
     */
    private function rhetoricBody(array $p3): array
    {
        $lines = [];
        if (isset($p3['concreteness'])) {
            $lines[] = "- 具体性: {$p3['concreteness']['level']} — {$p3['concreteness']['reason']}";
        }
        foreach ($p3['rhetoric_flags'] ?? [] as $f) {
            $lines[] = "- 「{$f['phrase']}」 → {$f['concern']}";
        }
        if (isset($p3['salary_breakdown'])) {
            $s = $p3['salary_breakdown'];
            $parts = ['固定残業: '.(($s['has_fixed_overtime'] ?? false) ? 'あり' : 'なし/不明')];
            $parts = [...$parts, ...$this->computedSalaryParts($s)];
            $lines = [...$lines, '', '### 実質給与分解', '', '- '.implode(' / ', $parts)];
            if (($s['note'] ?? '') !== '') {
                $lines[] = "- {$s['note']}";
            }
        }

        return $lines;
    }

    /**
     * 基本給・実質時給は PHP（SalaryBreakdownCalculator）が確定した computed から出す。
     * computed を持たない旧ランは黙って省略せず「未保存」と明示する（画面表示と同じ扱い）。
     *
     * @param  array<string, mixed>  $s  salary_breakdown
     * @return array<int, string>
     */
    private function computedSalaryParts(array $s): array
    {
        $computed = $s['computed'] ?? null;

        if (! is_array($computed)) {
            return ['基本給・実質時給: 未保存（再分析が必要）'];
        }

        if (($computed['unavailable_reason'] ?? null) !== null) {
            return ['基本給・実質時給: 算出不能 — '.$computed['unavailable_reason']];
        }

        $hours = $computed['monthly_work_hours'];

        return [
            '基本給: '.number_format((int) $computed['base_salary_yen']).'円/月',
            '実質時給: 約'.number_format((int) $computed['effective_hourly_yen'])."円（所定{$hours}h換算）",
        ];
    }

    /**
     * @param  array<string, mixed>  $p4
     * @return array<int, string>
     */
    private function reviewsBody(array $p4): array
    {
        $lines = [];
        // 件数はAIの作文（material_summary）でなくPHP確定値（material_meta）を正とする
        $meta = $p4['material_meta'] ?? null;
        if (is_array($meta)) {
            $line = "材料: 口コミ {$meta['total']}件中 {$meta['included']}件を投入";
            if (($meta['omitted'] ?? 0) > 0) {
                $line .= "（文字数上限のため {$meta['omitted']}件は未投入）";
            }
            $lines[] = $line;
        }
        $lines[] = $p4['material_summary'] ?? '';
        $groups = [
            ['複数一致（強い根拠）', $p4['strong_points'] ?? []],
            ['在籍/退職で割れた点', $p4['split_points'] ?? []],
            ['時期による変化（古い口コミ → 最近の口コミ）', $p4['time_changes'] ?? []],
            ['極端・偏りの可能性がある声（参考）', $p4['weak_or_extreme'] ?? []],
        ];
        foreach ($groups as [$title, $items]) {
            if ($items === []) {
                continue;
            }
            $lines = [...$lines, '', "### {$title}", '', ...array_map(fn ($s) => "- {$s}", $items)];
        }
        if (($p4['note'] ?? '') !== '') {
            $lines = [...$lines, '', $p4['note']];
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $p6
     * @return array<int, string>
     */
    private function questionsBody(array $p6): array
    {
        $lines = [];
        foreach ($p6['questions'] ?? [] as $i => $q) {
            $priority = self::PRIORITY_LABEL[$q['priority'] ?? ''] ?? ($q['priority'] ?? '');
            $askTo = self::ASK_TO_LABEL[$q['ask_to'] ?? ''] ?? ($q['ask_to'] ?? '');
            $lines[] = ($i + 1).". [{$priority}] {$q['question']}";
            $lines[] = "    - 確認: {$q['targets']} / 相手: {$askTo}";
            if (($q['casual_version'] ?? '') !== '') {
                $lines[] = "    - カジュアル版: {$q['casual_version']}";
            }
        }

        return $lines;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<int, string>
     */
    private function sourcesSection(array $sources): array
    {
        if ($sources === []) {
            return [];
        }

        $lines = ['', '## ソース一覧', ''];
        foreach ($sources as $s) {
            $url = $s['url'] !== null ? " — {$s['url']}" : '';
            $date = $s['fetchedAt'] ?? $s['createdAt'];
            $lines[] = "- {$s['typeLabel']}{$url}（取得: {$date}）";
        }

        return $lines;
    }
}
