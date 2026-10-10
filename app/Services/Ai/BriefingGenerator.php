<?php

namespace App\Services\Ai;

use App\Enums\AiPurpose;
use App\Enums\AiTier;
use App\Enums\AnalysisStep;
use App\Enums\SourceType;
use App\Models\AnalysisRun;
use App\Models\Company;

/**
 * 面接直前に読み返す1ページ分のブリーフィングを、完了済みカルテ（全ステップ出力）と
 * プロファイル・選考メモ・面接メモから生成する。
 *
 * カルテに無い新しい事実は作らせない。ただし面接メモ・選考メモは本人が直接聞き取った
 * 一次情報なので、事実の追加元としては使わせる（一次面接で答えが出た論点を、再分析を挟む前でも
 * 二次面接前のブリーフィングに再登場させないため）。
 */
class BriefingGenerator
{
    /** 面接メモ1件あたりの上限文字数（超過分は切り捨て） */
    private const MAX_INTERVIEW_CHARS = 6000;

    /**
     * 面接メモ全体の合算上限。面接を重ねるほどプロンプトが膨らみ codex がタイムアウトする
     * （GenerateBriefingJob::$timeout）ため、新しい回次から詰めて打ち切り、入らなかった件数は
     * 隠さず明示する。1件あたりの上限より大きく取るので、少なくとも1件は必ず入る。
     */
    private const MAX_INTERVIEW_TOTAL_CHARS = 18000;

    public function __construct(
        private readonly AiRunner $ai,
        private readonly ProfileText $profileText,
        private readonly SourceMaterialText $material,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function generate(AnalysisRun $run): array
    {
        $karte = [];
        // P6（逆質問）は自動実行しないが、生成済みならブリーフィングの材料にする（未生成なら null）
        foreach (AnalysisStep::karte() as $step) {
            $karte[$step->value] = $run->stepFor($step)?->output_json;
        }

        $company = $run->company;
        // カルテはWebページ由来の引用・要約を含む二次データ、企業名も一覧ページからAI抽出した値が
        // 入り得る。どちらも指示ではなくデータとして扱わせる境界で囲む
        $karteJson = UntrustedText::wrapDerived(json_encode($karte, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $rule = UntrustedText::RULE;
        $derivedRule = UntrustedText::DERIVED_RULE;
        $profile = $this->profileText->build();
        // 選考メモは本人が書く欄だが、スカウトメールや企業からの連絡文をそのまま貼ることが多く
        // 外部由来の文章が混ざる。面接メモだけ境界に入れて非対称にしないため同じ扱いにする
        $notes = trim((string) $company->selection_notes) !== ''
            ? UntrustedText::wrap((string) $company->selection_notes)
            : '（選考メモなし）';
        $interviewNotes = $this->interviewNotes($company);
        $position = $company->position ?: '（未設定）';
        $companyLine = UntrustedText::wrap("{$company->name}（応募ポジション: {$position}）");

        $prompt = <<<PROMPT
            転職者の面接準備を手伝ってください。以下の企業カルテ（AI分析の出力）と本人のプロファイル・面接での回答・選考メモから、面接直前に読み返す短いブリーフィングを作ります。

            {$rule}

            {$derivedRule}

            - summary_lines: この企業の要点（最大3行。1要素=1行）
            - open_issues: 未解決の論点（矛盾・判定割れ・検証不能のまま残っている点。各1文）
            - top_questions: 面接で聞くべき逆質問トップ5（カルテの逆質問から優先度順に選抜。言い換え可）
            - weak_points: 自分側が突っ込まれそうな点と一言の備え（プロファイル・選考メモ・面接での回答から。各1文）
            - note: 注意書き1文（カルテ生成時点の情報である旨）

            ルール:
            - 「ブラック」「嘘」等の断定語を使わない
            - カルテに無い新しい事実を作らない。ただし「# 面接での回答（一次情報・最優先）」「# 選考メモ」は本人が面接・選考の場で直接聞き取った一次情報なので、そこに書かれている内容は事実として扱ってよく、カルテの記述と食い違う場合はこちらを新しい情報として優先する
            - 選考メモ・面接メモで既に答えが出ている論点・逆質問は入れない（解決済みの論点を open_issues や top_questions に再掲しない）
            - カルテでは ❓検証不能 でも、面接での回答で答えが出ている論点は解決済みとして扱う
            - カルテの p2.verdicts のうち verdict が "split" のものは、複数の判定者が別々の観点で検証して解釈が分かれた主張。材料を足しても解消せず本人が面接で確かめるしかないため、未解決のものは必ず open_issues に入れる（各判定者の判断と根拠はその行の consensus.opinions にある）

            # 企業名
            {$companyLine}

            # 面接での回答（一次情報・最優先）
            {$interviewNotes}

            # 選考メモ
            {$notes}

            # カルテ（P1〜P6の出力JSON）
            {$karteJson}

            # プロファイル
            {$profile}
            PROMPT;

        // 要約・選抜には分析と同等の質が要るため、分析・要約 Tier で実行する
        /** @var array<string, mixed> */
        return $this->ai->runWithSchema(
            $this->schema(),
            $prompt,
            AiTier::Analysis,
            AiPurpose::Briefing,
            null,
            $company->id,
        );
    }

    /**
     * 面接メモ（SourceType::Interview）の素材テキスト。
     *
     * 整形の考え方は AnalysisRunner::sources() と同じ（見出し + 本文を UntrustedText::wrap の
     * 内側に入れ、1件あたりと合算の文字数上限で打ち切る）。AnalysisRunner の同処理は private で
     * 呼べないため、ここに同等の整形を持つ（将来 SourceMaterial のような共有クラスへ寄せたい）。
     *
     * 新しい回次を優先して詰める（次の面接に効くのは直近の回答なので、打ち切りで落とすのは古い方）。
     */
    private function interviewNotes(Company $company): string
    {
        $docs = $company->sourceDocuments()
            ->where('type', SourceType::Interview)
            ->orderByDesc('id')
            ->get();

        if ($docs->isEmpty()) {
            return '（面接メモなし）';
        }

        $blocks = [];
        $chars = 0;
        $omitted = 0;

        foreach ($docs as $doc) {
            // 見出しは AnalysisRunner と同じ「面接メモ #40」形式（面接メモは手入力でURLを持たない）
            $block = '【'.$doc->type->label().' #'.$doc->id."】\n"
                .mb_substr($this->material->of($doc), 0, self::MAX_INTERVIEW_CHARS);

            if ($chars + mb_strlen($block) > self::MAX_INTERVIEW_TOTAL_CHARS) {
                $omitted++;

                continue;
            }

            $blocks[] = $block;
            $chars += mb_strlen($block);
        }

        // 面接メモは本人が書いた文だが、面接官の発言や企業からの案内文の写しを含むため境界に入れる
        $text = UntrustedText::wrap(implode("\n\n", $blocks));

        if ($omitted > 0) {
            $text .= "\n\n（この他に{$omitted}件の面接メモがあるが、文字数上限のため今回の材料には含めていない）";
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $stringArray = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary_lines', 'open_issues', 'top_questions', 'weak_points', 'note'],
            'properties' => [
                'summary_lines' => $stringArray,
                'open_issues' => $stringArray,
                'top_questions' => $stringArray,
                'weak_points' => $stringArray,
                'note' => ['type' => 'string'],
            ],
        ];
    }
}
