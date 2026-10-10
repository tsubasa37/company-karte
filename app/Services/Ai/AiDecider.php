<?php

namespace App\Services\Ai;

use App\Enums\AiPurpose;
use App\Models\AiUsage;
use App\Services\Ai\Decision\ChoiceAnswer;
use App\Services\Ai\Decision\ChoiceQuestion;
use App\Services\Ai\Decision\DecisionDriverRegistry;
use App\Services\Ai\Decision\DecisionEngineNotConfigured;
use App\Services\Ai\Decision\DecisionNotSent;
use App\Services\Ai\Decision\DecisionRequest;
use App\Services\Ai\Decision\DecisionResponse;
use App\Services\Ai\Decision\NoulAnswer;
use App\Services\Ai\Decision\NoulQuestion;
use App\Services\Ai\Decision\Question;
use App\Services\Ai\Decision\ScoreAnswer;
use App\Services\Ai\Decision\ScoreQuestion;
use App\Services\Ai\Driver\AiRequestFailed;
use App\Services\Ai\Driver\TokenUsage;
use Illuminate\Support\Facades\Log;

/**
 * 判定の正規パス（1本）。state + 型付きの質問 → 確率つきの回答。生成の AiRunner とは種類の違う AI の正規パス。
 *
 * 手順: 未設定なら DecisionEngineNotConfigured → 上限検査（通信前）→ AiSettings::resolveDecision
 * → DecisionDriver::decide → 応答の値の検査 → 記録 → 応答。
 * 未設定は機能オフで、呼び出し元は isConfigured() で分岐する。設定済みで失敗したら記録してから例外を再送出する
 * （従来の挙動に黙って戻さない。別の実行先にも切り替えない）。
 * final にしない（テストが Mockery::mock(AiDecider::class) で差し替えるため）。
 */
class AiDecider
{
    /** 確率の合計の許容誤差（丸めの分） */
    private const SUM_TOLERANCE = 0.01;

    /** 浮動小数点の表現誤差の分。これが無いと合計 0.99 / 1.01 ちょうどが弾かれる */
    private const FLOAT_EPSILON = 1e-9;

    private const PROBE_STATE = 'これは接続テストです。';

    public function __construct(
        private readonly AiSettings $settings,
        private readonly DecisionDriverRegistry $drivers,
        private readonly AiPricing $pricing,
    ) {}

    public function isConfigured(): bool
    {
        return $this->settings->isDecisionConfigured();
    }

    /**
     * @param  AiPurpose  $purpose  何のための判定か（使用量画面の内訳に使う）
     * @param  string|null  $detail  用途の中の内訳
     * @param  int|null  $companyId  どの企業のための判定か
     */
    public function decide(DecisionRequest $request, AiPurpose $purpose, ?string $detail = null, ?int $companyId = null): DecisionResponse
    {
        return $this->execute($request, $purpose, $detail, $companyId)['response'];
    }

    /**
     * まとめて判定する（1 リクエスト = 1 回の判定を並列に送る）。上限検査は全件を送る前に行い、1 件でも超えれば何も送らない。
     * 成功・失敗とも 1 件 1 行で記録し、1 件でも失敗があれば全件の記録を終えてから例外にする
     * （部分成功の応答は返さない。欠けた回答で偏った材料を作らないため）。
     *
     * @param  list<DecisionRequest>  $requests
     * @return list<DecisionResponse> $requests と同じ添字
     */
    public function decideMany(array $requests, AiPurpose $purpose, ?string $detail = null, ?int $companyId = null): array
    {
        if (! $this->isConfigured()) {
            throw new DecisionEngineNotConfigured;
        }

        if ($requests === [] || ! array_is_list($requests)) {
            throw new \InvalidArgumentException('判定の一括実行には 1 件以上のリクエストを添字 0 からの並びで渡してください');
        }

        foreach ($requests as $request) {
            if (! $request instanceof DecisionRequest) {
                throw new \InvalidArgumentException('判定の一括実行に DecisionRequest でない要素があります');
            }
            $this->assertWithinLimits($request);
        }

        $target = $this->settings->resolveDecision();
        $startedAt = microtime(true);

        try {
            $results = $this->drivers->for($target->provider)->decideMany($requests, $target);
        } catch (\Throwable $e) {
            // ドライバが全体として失敗した（送る前の設定の不備等）。decide を件数ぶん呼んだときと同じく 1 件 1 行で残す
            $durationMs = $this->elapsedMs($startedAt);
            foreach ($requests as $request) {
                $this->record($purpose, $target, $detail, $companyId, $durationMs, $request, '', null, $e);
            }

            throw $e;
        }

        // 並列実行のため 1 件ごとの時間は取れない。各行に一括全体の所要時間を入れる
        $durationMs = $this->elapsedMs($startedAt);
        $responses = [];
        $failures = [];
        $notSent = 0;

        foreach ($requests as $i => $request) {
            $result = $results[$i] ?? new \LogicException("判定の一括実行の結果に {$i} 番目の要素がありません");

            // 送っていないリクエストは通信していないので使用量に記録しない
            if ($result instanceof DecisionNotSent) {
                $notSent++;

                continue;
            }

            $settled = $this->settleOne($request, $result, $target);
            $tokens = $settled instanceof DecisionResponse ? $settled->tokens : $this->tokensOf($settled, $result);
            $output = $settled instanceof DecisionResponse ? json_encode($settled->answers, JSON_UNESCAPED_UNICODE) : '';

            $this->record($purpose, $target, $detail, $companyId, $durationMs, $request, $output, $tokens, $settled instanceof \Throwable ? $settled : null);

            if ($settled instanceof \Throwable) {
                $failures[] = $settled;
            } else {
                $responses[$i] = $settled;
            }
        }

        if ($failures !== []) {
            $unsent = $notSent > 0 ? "（残り {$notSent} 件は送っていません）" : '';

            throw new \RuntimeException(
                '判定の一括実行で '.count($failures).' / '.count($requests).' 件が失敗しました'.$unsent.'。最初の失敗: '.$failures[0]->getMessage(),
                0,
                $failures[0],
            );
        }

        return $responses;
    }

    /** ドライバの 1 要素に decide() と同じ応答検証を通す。不正なら例外オブジェクトを返す（ここでは投げない） */
    private function settleOne(DecisionRequest $request, mixed $result, AiTarget $target): DecisionResponse|\Throwable
    {
        if ($result instanceof \Throwable) {
            return $result;
        }

        if (! $result instanceof DecisionResponse) {
            return new \LogicException('判定の一括実行の結果に DecisionResponse でない要素があります');
        }

        try {
            $this->assertAnswers($request, $result, $target);
        } catch (AiRequestFailed $e) {
            return $e;
        }

        return $result;
    }

    /** 失敗の記録に載せるトークン。課金済みの失敗（AiRequestFailed）だけ usage を残す */
    private function tokensOf(\Throwable $error, mixed $result): ?TokenUsage
    {
        return match (true) {
            $error instanceof AiRequestFailed => $error->usage,
            $result instanceof DecisionResponse => $result->tokens,
            default => null,
        };
    }

    /**
     * 接続テスト。極小の noul 質問を 1 回判定し、purpose=ConnectionTest で記録する（経路は decide と同じ）。
     *
     * 戻り値は今回の実行の記録。記録できなかったときは null（過去の記録で代用しない）。
     */
    public function probe(): ?AiUsage
    {
        $request = new DecisionRequest(self::PROBE_STATE, [new NoulQuestion('is_test', 'state は接続テストの文章か')]);

        return $this->execute($request, AiPurpose::ConnectionTest)['usage'];
    }

    /**
     * @return array{response: DecisionResponse, usage: AiUsage|null}
     */
    private function execute(DecisionRequest $request, AiPurpose $purpose, ?string $detail = null, ?int $companyId = null): array
    {
        if (! $this->isConfigured()) {
            throw new DecisionEngineNotConfigured;
        }

        $this->assertWithinLimits($request);
        $target = $this->settings->resolveDecision();

        $startedAt = microtime(true);
        $response = null;

        try {
            $response = $this->drivers->for($target->provider)->decide($request, $target);
            $this->assertAnswers($request, $response, $target);
        } catch (\Throwable $e) {
            // 失敗も消費として記録する。課金済みの失敗（AiRequestFailed）は usage も残す
            $tokens = match (true) {
                $e instanceof AiRequestFailed => $e->usage,
                $response !== null => $response->tokens,
                default => null,
            };
            $this->record($purpose, $target, $detail, $companyId, $this->elapsedMs($startedAt), $request, '', $tokens, $e);

            throw $e;
        }

        $output = json_encode($response->answers, JSON_UNESCAPED_UNICODE);
        $usage = $this->record($purpose, $target, $detail, $companyId, $this->elapsedMs($startedAt), $request, $output, $response->tokens, null);

        return ['response' => $response, 'usage' => $usage];
    }

    /** 送る前に自前の上限で落とす（公式の上限はトークン予算だけなので、超えそうな入力を通信前に止める） */
    private function assertWithinLimits(DecisionRequest $request): void
    {
        $maxQuestions = $this->limit('max_questions');
        $maxOptions = $this->limit('max_choice_options');
        $maxStateChars = $this->limit('max_state_chars');

        $questions = count($request->questions);
        if ($questions > $maxQuestions) {
            throw new \InvalidArgumentException("判定の質問が多すぎます（{$questions} 件。上限 {$maxQuestions} 件）");
        }

        foreach ($request->questions as $question) {
            if ($question instanceof ChoiceQuestion && count($question->criteria) > $maxOptions) {
                throw new \InvalidArgumentException(
                    "判定の質問 {$question->id} の候補が多すぎます（".count($question->criteria)." 個。上限 {$maxOptions} 個）",
                );
            }
        }

        $stateChars = $request->stateChars();
        if ($stateChars > $maxStateChars) {
            throw new \InvalidArgumentException(
                '判定の state が長すぎます（'.number_format($stateChars).' 字。上限 '.number_format($maxStateChars).' 字）',
            );
        }
    }

    private function limit(string $key): int
    {
        $value = config("ai.decision.limits.{$key}");

        if (! is_numeric($value) || (int) $value <= 0) {
            throw new \RuntimeException("config/ai.php の decision.limits.{$key} が設定されていません");
        }

        return (int) $value;
    }

    /**
     * 回答の値を検査する。形はドライバが見ているが、範囲・合計・候補内かはここで見る（どのドライバでも同じ基準にする）。
     * 応答自体は返っている（課金済み）ので usage 付きの AiRequestFailed にする。
     */
    private function assertAnswers(DecisionRequest $request, DecisionResponse $response, AiTarget $target): void
    {
        foreach ($request->questions as $question) {
            $problem = $this->problemOf($question, $response);

            if ($problem !== null) {
                throw new AiRequestFailed(
                    "{$target->provider->label()} の判定応答が不正です（質問 {$question->id()}: {$problem}）",
                    $target->provider,
                    $target->model,
                    $response->tokens,
                );
            }
        }
    }

    /** 問題があればその説明、無ければ null */
    private function problemOf(Question $question, DecisionResponse $response): ?string
    {
        $answer = $response->answers[$question->id()] ?? null;

        return match (true) {
            $answer === null => '回答がありません',
            $question instanceof ChoiceQuestion => $answer instanceof ChoiceAnswer ? $this->choiceProblem($question, $answer) : '回答の型が choice ではありません',
            $question instanceof ScoreQuestion => $answer instanceof ScoreAnswer ? $this->scoreProblem($question, $answer) : '回答の型が score ではありません',
            $question instanceof NoulQuestion => $answer instanceof NoulAnswer
                ? ($this->isUnit($answer->noul) ? null : "noul が 0〜1 の範囲外です（{$answer->noul}）")
                : '回答の型が noul ではありません',
            default => '未対応の質問の型です',
        };
    }

    private function choiceProblem(ChoiceQuestion $question, ChoiceAnswer $answer): ?string
    {
        $options = $question->options();

        if (! in_array($answer->choice, $options, true)) {
            return "choice が候補にありません（{$answer->choice}）";
        }

        $returned = array_map(strval(...), array_keys($answer->probabilities));
        if (array_diff($returned, $options) !== [] || array_diff($options, $returned) !== []) {
            return 'probabilities の候補が質問の候補と一致しません';
        }

        return $this->distributionProblem($answer->probabilities) ?? $this->confidenceProblem($answer->confidence);
    }

    private function scoreProblem(ScoreQuestion $question, ScoreAnswer $answer): ?string
    {
        $levels = count($question->levels);

        if (count($answer->probabilities) !== $levels) {
            return 'probabilities の個数が段階数と一致しません（'.count($answer->probabilities)." 個。段階 {$levels} 個）";
        }

        // score は levels の 1 始まりの位置として扱う
        if ($answer->score < 1 || $answer->score > $levels) {
            return "score が 1〜{$levels} の範囲外です（{$answer->score}）";
        }

        return $this->distributionProblem($answer->probabilities) ?? $this->confidenceProblem($answer->confidence);
    }

    /**
     * @param  array<array-key, float>  $probabilities
     */
    private function distributionProblem(array $probabilities): ?string
    {
        foreach ($probabilities as $key => $value) {
            if (! $this->isUnit($value)) {
                return "probabilities.{$key} が 0〜1 の範囲外です（{$value}）";
            }
        }

        $sum = array_sum($probabilities);
        if (abs($sum - 1.0) > self::SUM_TOLERANCE + self::FLOAT_EPSILON) {
            return "probabilities の合計が 1 ではありません（{$sum}）";
        }

        return null;
    }

    private function confidenceProblem(float $confidence): ?string
    {
        return $this->isUnit($confidence) ? null : "confidence が 0〜1 の範囲外です（{$confidence}）";
    }

    private function isUnit(float $value): bool
    {
        return $value >= 0.0 && $value <= 1.0;
    }

    /**
     * 判定 1 回ぶんを記録する（項目は AiRunner と同じ）。計測が原因で判定を落とさないよう、
     * 記録できない場合もログに残して処理は続ける（記録は計測であって本処理ではない）。
     */
    private function record(
        AiPurpose $purpose,
        AiTarget $target,
        ?string $detail,
        ?int $companyId,
        int $durationMs,
        DecisionRequest $request,
        string|false $output,
        ?TokenUsage $tokens,
        ?\Throwable $error,
    ): ?AiUsage {
        try {
            return AiUsage::create([
                'provider' => $target->provider,
                'purpose' => $purpose,
                'model' => $target->model,
                'detail' => $detail,
                'company_id' => $companyId,
                'duration_ms' => $durationMs,
                'prompt_chars' => $request->stateChars() + $request->questionsChars(),
                'output_chars' => $output === false ? 0 : mb_strlen($output),
                'input_tokens' => $tokens?->input,
                'cached_input_tokens' => $tokens?->cached,
                'cache_write_input_tokens' => $tokens?->cacheWrite,
                'output_tokens' => $tokens?->output,
                'reasoning_tokens' => $tokens?->reasoning,
                'cost_micro_usd' => $this->cost($target, $tokens),
                'usage_raw' => $tokens?->raw,
                'succeeded' => $error === null,
                'error' => $error?->getMessage(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AI の使用量を記録できませんでした: '.$e->getMessage());

            return null;
        }
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * 推定費用は実行時点の単価表で確定する。単価未登録・トークン不明は null（0 で埋めない）。
     * 単価表の不備で実行の記録ごと失わないよう、ここだけ独立して受ける（AiRunner::cost と同じ）。
     */
    private function cost(AiTarget $target, ?TokenUsage $tokens): ?int
    {
        try {
            return $this->pricing->costMicroUsd($target->provider, $target->model, $tokens);
        } catch (\Throwable $e) {
            Log::warning('AI の推定費用を計算できませんでした（単価表を確認してください）: '.$e->getMessage());

            return null;
        }
    }
}
