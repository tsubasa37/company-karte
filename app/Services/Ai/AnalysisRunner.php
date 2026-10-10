<?php

namespace App\Services\Ai;

use App\Enums\AiPurpose;
use App\Enums\AiTier;
use App\Enums\AnalysisStep;
use App\Enums\IssueStatus;
use App\Enums\SourceType;
use App\Enums\VerdictPerspective;
use App\Models\Company;
use App\Models\OpenIssue;
use App\Models\ReviewItem;
use App\Models\SourceDocument;
use App\Services\ReviewPeriod;

/**
 * 1ステップ分の分析を実行する。
 * プロンプト（resources/prompts/pN.txt）を読み、企業データ・プロファイル・前ステップ結果を
 * 差し込み、AiRunner に構造化スキーマ付きで投げて配列を返す。
 * 数値・素材整形は PHP で行い、codex には解釈・判定・要約だけをさせる。
 */
class AnalysisRunner
{
    /** 分析時に codex へ渡すソース1件あたりの上限文字数（超過分は切り捨て。画面表示にも使う） */
    public const MAX_SOURCE_CHARS = 8000;

    /**
     * 求人票系ソースの上限文字数。給与・勤務地がページ後半にあることが多く、
     * 8000字だと切り落ちる実例があったため広く取る（ノイズ除去済みの本文が入る前提）。
     */
    public const MAX_JOBPOSTING_CHARS = 20000;

    /** 口コミ（分割済みアイテム）を合算で渡す上限文字数。超過分は件数を明示して未投入にする */
    public const MAX_REVIEW_CHARS = 24000;

    /** 口コミ材料の並び順（reviewMaterialMeta の ordering） */
    public const ORDER_RELEVANCE = 'relevance';

    public const ORDER_RECENCY = 'recency';

    /** 求人票として扱うソース種別（主張抽出・条件抽出の対象） */
    private const JOBPOSTING_TYPES = [SourceType::JobPosting, SourceType::Other];

    /**
     * 直近の材料整形で数えた口コミ件数（total=分割済み全件 / included=材料に入れた件数 /
     * omitted=文字数上限で入れなかった件数）と並び順（relevance=機械判定の関連度順 / recency=新しい順）。
     * runStep() の実行前は null。
     *
     * @var array{total: int, included: int, omitted: int, ordering: string}|null
     */
    private ?array $reviewMeta = null;

    public function __construct(
        private readonly AiRunner $ai,
        private readonly AnalysisSchemas $schemas,
        private readonly ProfileText $profileText,
        private readonly ReviewPeriod $period,
        private readonly SourceMaterialText $material,
        private readonly AiSettings $settings,
    ) {}

    /**
     * 口コミ材料の内訳。「何件のうち何件を渡したか」を画面・ステップ記録に出すために使う。
     * 材料整形前に呼ぶと 0 件と区別できないため例外にする（サイレントに 0 を返さない）。
     *
     * @return array{total: int, included: int, omitted: int, ordering: string}
     */
    public function reviewMaterialMeta(): array
    {
        if ($this->reviewMeta === null) {
            throw new \LogicException('reviewMaterialMeta() は runStep() で材料を整形した後にしか取得できない');
        }

        return $this->reviewMeta;
    }

    /**
     * @param  array<string, array<string, mixed>>  $prior  step値 => 出力配列
     * @param  VerdictPerspective|null  $perspective  判定者の観点（P2 のみ必須。他ステップは観点を持たない）
     * @return array<string, mixed>
     */
    public function runStep(Company $company, AnalysisStep $step, array $prior, ?VerdictPerspective $perspective = null): array
    {
        // P2 は必ず観点付きで実行する（人数1のときも Base を渡す）。
        // 観点なしの経路を残すと、多重化前後で2本の経路ができてしまう
        if ($step === AnalysisStep::P2 && $perspective === null) {
            throw new \LogicException('p2 は判定者の観点（VerdictPerspective）が必要です');
        }

        $prompt = $this->buildPrompt($company, $step, $prior, $perspective);

        // 分析は解釈・判定の質が要るため、分析・要約 Tier で実行する（実行先・モデルは設定で選ぶ）
        // P6 は分析から切り離した単独生成なので、使用量の内訳でも別扱いにする
        $purpose = $step === AnalysisStep::P6 ? AiPurpose::Questions : AiPurpose::Analysis;

        return $this->ai->runWithSchema(
            $this->schemas->for($step),
            $prompt,
            AiTier::Analysis,
            $purpose,
            $step->value,
            $company->id,
        );
    }

    /**
     * そのステップに codex を回すだけの素材があるか。
     * 口コミは分割済みアイテム（review_items）が正の材料。分析ジョブが開始時に
     * 未分割ソースを同期分割するため、ここではアイテムの有無だけを見ればよい。
     */
    public function hasMaterialFor(Company $company, AnalysisStep $step): bool
    {
        $has = fn (array $types) => $company->sourceDocuments->contains(
            fn ($d) => in_array($d->type, $types, true),
        );

        return match ($step) {
            // P2(裏取り)・P6(逆質問)は P1 が求人票から抽出した主張が起点。求人票が無いと
            // P1 がスキップされ主張リストが空になるため、codex を呼んでも空配列しか返らない。
            AnalysisStep::P1, AnalysisStep::P2, AnalysisStep::P3, AnalysisStep::P6 => $has(self::JOBPOSTING_TYPES),
            AnalysisStep::P4 => $company->reviewItems()->exists(),
            // RJ は P1 の主張と口コミを突き合わせるので、両方の材料が要る
            AnalysisStep::RJ => $has(self::JOBPOSTING_TYPES) && $company->reviewItems()->exists(),
            // P5 は求人票が無くても実行する。RecommendationEvaluator は「求人票未投入」を
            // Hold として機械確定するが、その入力になる must/want/ng の充足判定は
            // 口コミ・公式データだけでも作れるため、画面に出す所見を空欄にしない。
            AnalysisStep::P5 => true,
        };
    }

    /**
     * 素材が無くてスキップになる理由（analysis_steps に残す文言）。
     * 「素材未投入」だけだと何を足せば動くのか分からないため、ステップごとに何が無いのかを返す。
     */
    public function skipReasonFor(Company $company, AnalysisStep $step): string
    {
        if ($this->hasMaterialFor($company, $step)) {
            throw new \LogicException("{$step->value} は素材があるためスキップ理由がない");
        }

        return match ($step) {
            AnalysisStep::P1 => '求人票が未投入のため、検証できる主張を抽出できない',
            AnalysisStep::P2 => '求人票が未投入のため、裏取りする主張がない',
            AnalysisStep::P3 => '求人票が未投入のため、言い回しと給与記載を確認できない',
            AnalysisStep::P4 => '口コミが未投入のため、要約する材料がない',
            AnalysisStep::RJ => '求人票か口コミが未投入のため、主張と口コミを突き合わせる材料がない',
            AnalysisStep::P6 => '求人票が未投入のため、逆質問の元になる主張がない',
            AnalysisStep::P5 => throw new \LogicException('p5 は素材の有無でスキップしない'),
        };
    }

    /**
     * P1 の引用が実在するか照合するための、求人票系ソースの素材テキスト（境界タグなし）。
     */
    public function jobPostingText(Company $company): string
    {
        return $company->sourceDocuments
            ->filter(fn ($d) => in_array($d->type, self::JOBPOSTING_TYPES, true))
            ->map(fn ($d) => mb_substr($this->material->of($d), 0, self::MAX_JOBPOSTING_CHARS))
            ->implode("\n\n");
    }

    /**
     * @param  array<string, array<string, mixed>>  $prior
     */
    private function buildPrompt(Company $company, AnalysisStep $step, array $prior, ?VerdictPerspective $perspective): string
    {
        $path = resource_path('prompts/'.$step->promptFile());
        $template = file_get_contents($path);
        if ($template === false) {
            throw new \RuntimeException("Prompt template not found: {$path}");
        }

        $vars = [
            'untrusted_rule' => UntrustedText::RULE,
            'derived_rule' => UntrustedText::DERIVED_RULE,
            // 判定者の観点。PHP が持つ固定文字列なので untrusted 境界には入れない
            // （Base は空文字で、多重化前のプロンプトと同じ内容になる）
            'perspective' => $perspective?->instruction() ?? '',
            'jobposting_sources' => $this->sources($company, self::JOBPOSTING_TYPES, '（求人票未提供）'),
            'reviews' => $this->reviews($company, $step, $prior),
            'news_sources' => $this->sources($company, [SourceType::News], '（ニュース未提供）'),
            'official_data' => $this->officialData($company),
            'profile' => $this->profileText->build(),
            'p1_claims' => $this->priorJson($prior, AnalysisStep::P1),
            'p2_verdicts' => $this->priorJson($prior, AnalysisStep::P2),
            'p3_rhetoric' => $this->priorJson($prior, AnalysisStep::P3),
            'p4_reviews' => $this->priorJson($prior, AnalysisStep::P4),
            'p5_checks' => $this->p5Checks($prior),
            'interview_notes' => $this->sources($company, [SourceType::Interview], '（面接メモなし）'),
            // 本人が書いたメモだが、求人票や口コミからの貼り付けが混ざりうるため
            // 面接メモと同じく境界の内側に入れる（BriefingGenerator と扱いを揃える）
            'selection_notes' => trim((string) $company->selection_notes) !== ''
                ? UntrustedText::wrap((string) $company->selection_notes)
                : '（選考メモなし）',
            // 面接で答えが出た論点。同じことを逆質問に再掲させないために渡す（使うのは P6 だけ）
            'resolved_issues' => $step === AnalysisStep::P6 ? $this->resolvedIssues($company) : '',
        ];

        // 置換はテンプレート側のプレースホルダを1回ずつ辿る1パスで行う。
        // str_replace の一括置換だと、先に差し込んだ本文に後続のプレースホルダ文字列
        // （例「{{interview_notes}}」）が書かれていた場合それも展開され、展開値が持つ閉じタグで
        // 外側の untrusted 境界を抜けられてしまう。
        $prompt = preg_replace_callback(
            '/\{\{([a-z0-9_]+)\}\}/',
            function (array $m) use ($vars, $path): string {
                // テンプレートとコードの不一致は黙って通さず、素材が欠けたまま分析させない
                if (! array_key_exists($m[1], $vars)) {
                    throw new \RuntimeException("Unknown prompt placeholder {$m[0]} in {$path}");
                }

                return $vars[$m[1]];
            },
            $template,
        );

        if ($prompt === null) {
            throw new \RuntimeException("Failed to expand prompt template: {$path}");
        }

        return $prompt;
    }

    /**
     * 面接で答えが出た（解消した）論点の一覧。
     * 逆質問（P6）で同じ論点を繰り返し出させないために渡す。
     * 未解消・未確認の論点は渡さない（それらは引き続き聞く対象なので、除外の材料にしない）。
     */
    private function resolvedIssues(Company $company): string
    {
        $lines = $company->openIssues()
            ->where('status', IssueStatus::Resolved->value)
            ->orderBy('id')
            ->get()
            ->map(function (OpenIssue $issue) {
                $note = trim((string) $issue->resolution_note);

                return $note === ''
                    ? "- {$issue->claim}"
                    : "- {$issue->claim}（面接での回答: {$note}）";
            })
            ->implode("\n");

        // 論点の本文は P2 の出力（AI 由来の二次データ）なので、指示ではなくデータとして扱わせる
        return $lines === '' ? '（解消済みの論点なし）' : UntrustedText::wrapDerived($lines);
    }

    /**
     * @param  array<int, SourceType>  $types
     */
    private function sources(Company $company, array $types, string $empty): string
    {
        $text = $this->sourceText($company, $types);

        // Webページ由来の文章なので、指示ではなくデータとして扱わせる境界で囲む
        return $text === '' ? $empty : UntrustedText::wrap($text);
    }

    /**
     * 境界タグを付けない素材テキスト（該当ソースが無ければ空文字）。
     * 他の素材と1つの境界にまとめたい呼び出し元（officialData）が使う。
     *
     * @param  array<int, SourceType>  $types
     */
    private function sourceText(Company $company, array $types): string
    {
        return $company->sourceDocuments
            ->filter(fn ($d) => in_array($d->type, $types, true))
            ->map(function ($d) {
                $body = mb_substr($this->material->of($d), 0, self::limitFor($d->type));

                return '【'.$this->sourceRef($d)."】\n".$body;
            })->implode("\n\n");
    }

    /**
     * 材料の見出しに使う参照名（例「求人票 #12 / tenshoku.mynavi.jp」）。
     * P2 が checked_against / source_ids でどの材料を見たかを示せるようにソースIDを入れ、
     * どの媒体かが分かるようにホスト名を添える。
     * 手貼り（url なし）や URL からホストが取れない場合はIDだけにする（例「面接メモ #40」）。
     *
     * ホスト名はクリップ元ページ由来の外部テキストなので、この文字列は必ず
     * UntrustedText::wrap の内側に入れる（呼び出し元の sources()/reviews()/officialData() が包む）。
     */
    private function sourceRef(SourceDocument $doc): string
    {
        $ref = $doc->type->label().' #'.$doc->id;
        $host = $doc->url === null ? null : parse_url($doc->url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $ref.' / '.$host : $ref;
    }

    /**
     * ソース種別ごとの上限文字数。求人票は条件が後半に来るため広く取る。
     * 画面（ソース詳細の切り捨て表示）でも同じ判定を使う。
     */
    public static function limitFor(SourceType $type): int
    {
        return in_array($type, self::JOBPOSTING_TYPES, true)
            ? self::MAX_JOBPOSTING_CHARS
            : self::MAX_SOURCE_CHARS;
    }

    /**
     * 口コミは分割済みアイテム（1口コミ=1件・重複除去済み）を材料にする。
     * 投稿時期で「最近」「それ以前」に分けて渡し、古い情報に引きずられないようにする。
     * 新しく取り込んだものを優先し、上限で入り切らない件数は隠さず明示する。
     * P2 だけは、設定で有効なら機械判定（RJ）の関連度が高い順に詰める（relevanceFor）。
     *
     * @param  array<string, array<string, mixed>>  $prior
     */
    private function reviews(Company $company, AnalysisStep $step, array $prior): string
    {
        // 見出しにソースID・ホスト名を出すため、由来のソースも一緒に読む
        $items = $company->reviewItems()->with('sourceDocument')->latest('id')->get();
        $relevance = $this->relevanceFor($step, $prior);

        if ($items->isEmpty()) {
            $this->reviewMeta = ['total' => 0, 'included' => 0, 'omitted' => 0, 'ordering' => self::ORDER_RECENCY];

            return '（口コミ未提供）';
        }

        if ($relevance !== null) {
            // 関連度の高い順、同じなら新しい順。判定の後に取り込んだ口コミ（関連度が無い）は判定済みの後ろに新しい順で置く
            $items = $items->sortBy([
                fn (ReviewItem $a, ReviewItem $b) => ($relevance[$b->id] ?? -1.0) <=> ($relevance[$a->id] ?? -1.0),
                fn (ReviewItem $a, ReviewItem $b) => $b->id <=> $a->id,
            ])->values();
        }

        $currentYear = (int) now()->format('Y');
        $groups = [ReviewPeriod::RECENT => [], ReviewPeriod::OLD => [], ReviewPeriod::UNKNOWN => []];
        $chars = 0;
        $omitted = 0;

        foreach ($items as $item) {
            $block = '【'.$this->reviewRef($item)."】\n{$item->body}";

            if ($chars + mb_strlen($block) > self::MAX_REVIEW_CHARS) {
                $omitted++;

                continue;
            }

            $groups[$this->period->classifyReview($item->posted_period, $item->tenure_period, $currentYear)][] = $block;
            $chars += mb_strlen($block);
        }

        $this->reviewMeta = [
            'total' => $items->count(),
            'included' => $items->count() - $omitted,
            'omitted' => $omitted,
            'ordering' => $relevance !== null ? self::ORDER_RELEVANCE : self::ORDER_RECENCY,
        ];

        $text = UntrustedText::wrap($this->groupedReviewText($groups));

        if ($omitted > 0) {
            $text .= "\n\n（この他に{$omitted}件の口コミがあるが、文字数上限のため今回の材料には含めていない）";
        }

        return $text;
    }

    /**
     * P2 の口コミを関連度順に並べるときの、口コミ ID => 関連度。並べないなら null。
     *
     * 設定（判定エンジンの材料選抜）に応じた並び順の選択で、失敗時の代替ではない。
     * RJ が動かなかったラン（未設定・素材なしで skipped）は関連度が無いので新しい順のまま。
     * P4 は主張に無い話題も読む必要があるため、常に新しい順にする。
     *
     * @param  array<string, array<string, mixed>>  $prior
     * @return array<int, float>|null
     */
    private function relevanceFor(AnalysisStep $step, array $prior): ?array
    {
        $relevance = $prior[AnalysisStep::RJ->value]['relevance'] ?? null;

        if ($step !== AnalysisStep::P2 || ! is_array($relevance) || $relevance === []
            || ! $this->settings->isDecisionReviewOrderingEnabled()) {
            return null;
        }

        return $relevance;
    }

    /**
     * 口コミ1件の見出し（例「口コミ #34 / openwork.jp（現職 / 年収・評価 / 投稿2026年 / 在籍2020年〜2024年 / エンジニア）」）。
     * 属性は分かっているものだけを出す（空の属性は書かない）。
     */
    private function reviewRef(ReviewItem $item): string
    {
        $posted = trim((string) $item->posted_period);
        $tenure = trim((string) $item->tenure_period);

        $attributes = array_filter([
            $item->stance,
            $item->category,
            $posted !== '' ? '投稿'.$posted : null,
            $tenure !== '' ? '在籍'.$tenure : null,
            $item->job_type,
        ]);

        return $this->sourceRef($item->sourceDocument)
            .($attributes === [] ? '' : '（'.implode(' / ', $attributes).'）');
    }

    /**
     * 新旧グループを見出しつきのテキストにする（空のグループは出さない）。
     *
     * @param  array<string, array<int, string>>  $groups
     */
    private function groupedReviewText(array $groups): string
    {
        $sections = [];
        foreach ($groups as $classification => $blocks) {
            if ($blocks === []) {
                continue;
            }
            $label = $this->period->label($classification);
            $sections[] = "=== {$label}（".count($blocks)."件） ===\n\n".implode("\n\n", $blocks);
        }

        return implode("\n\n", $sections);
    }

    private function officialData(Company $company): string
    {
        $lines = $company->officialMetrics->map(function ($m) {
            $unit = $m->unit ? " {$m->unit}" : '';
            $year = $m->fiscal_year ? "（{$m->fiscal_year}）" : '';

            return "- [{$m->source->label()}] {$m->key}: {$m->value}{$unit}{$year}";
        })->all();

        // 指標行にも gBizINFO の認定名など外部API由来のテキストが載るため、原文ソースと
        // まとめて1つの境界の内側に入れる（sources() を使うと二重に包まれるので素材だけ取る）
        $joined = trim(implode("\n", $lines)."\n".$this->sourceText($company, [SourceType::Edinet, SourceType::Shokuba]));

        return $joined !== '' ? UntrustedText::wrap($joined) : '（公式データ未提供）';
    }

    /**
     * P6 に渡す P5 の充足判定。must_checks / want_checks / ng_checks だけに絞る。
     *
     * P5 の保存済み出力には AnalyzeCompanyJob が入れた確定おすすめ度
     * （recommendation / recommendation_reason）も含まれる。これをそのまま渡すと
     * 逆質問が「もう判定は出ている」という結論に引きずられるため、判定材料だけを渡す。
     *
     * @param  array<string, array<string, mixed>>  $prior
     */
    private function p5Checks(array $prior): string
    {
        $p5 = $prior[AnalysisStep::P5->value] ?? null;

        if ($p5 === null) {
            return '（未実行）';
        }

        $checks = array_intersect_key($p5, array_flip(['must_checks', 'want_checks', 'ng_checks']));

        return UntrustedText::wrapDerived(json_encode($checks, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * @param  array<string, array<string, mixed>>  $prior
     */
    private function priorJson(array $prior, AnalysisStep $step): string
    {
        $data = $prior[$step->value] ?? null;

        // 未実行の印はPHPが出す固定文字列で、外部由来の文章を含まないため境界で囲まない。
        // 前段の出力はWebページ由来の引用・要約を含むので、指示ではなくデータとして扱わせる境界で囲む。
        return $data === null
            ? '（未実行）'
            : UntrustedText::wrapDerived(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
