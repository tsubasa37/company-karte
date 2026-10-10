<?php

namespace App\Services\Ai\Decision;

use App\Enums\AiProvider;
use App\Services\Ai\AiTarget;
use App\Services\Ai\ApiKeys;
use App\Services\Ai\Driver\AiRequestFailed;
use App\Services\Ai\Driver\TokenUsage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Jev（TypeSafe AI）の判定 API（POST {base_url}/systemone）で、型付きの質問に確率つきで答えさせる。
 *
 * （確認 2026-09-23: リクエスト・レスポンスの形は公開情報をもとにしたもの。実際の応答との照合はまだ）
 *
 * - HTTP エラー・JSON でない本文は日本語文言にする。Http の例外はそのまま再送出しない
 * - 応答の形が壊れている（回答欠落・型不一致・必須項目なし）のは課金済みなので usage 付きの AiRequestFailed にする
 * - 値の範囲（確率の合計・候補内か等）は AiDecider が検査する。ここは形だけを見る
 * - リトライはしない（フェイルファスト）
 * - 一括（decideMany）も 1 件ずつ同じ評価を通し、失敗はその要素の値として返す
 */
class JevClient implements DecisionDriver
{
    /** 想定外の本文を文言に載せる長さ（全文をログ・記録に流さない） */
    private const BODY_EXCERPT = 200;

    public function __construct(private readonly ApiKeys $keys) {}

    public function decide(DecisionRequest $request, AiTarget $target): DecisionResponse
    {
        $model = $this->model($target);

        return $this->evaluate($this->send($target, $this->body($request, $model)), $request, $model);
    }

    /**
     * Http::pool で並列に送る。同時数ごとに区切り、区切りの中は並列・区切りの間は直列。
     * モデル名・接続先・キーの不備は送る前に全体を止める。要素ごとの失敗は例外オブジェクトを値にして返す。
     *
     * @param  list<DecisionRequest>  $requests
     * @return list<DecisionResponse|\Throwable>
     */
    public function decideMany(array $requests, AiTarget $target): array
    {
        $model = $this->model($target);
        // キーは引数に載せない。例外のスタックトレースに値が出るため
        $apiKey = $this->keys->for(AiProvider::Jev);
        $url = $this->endpoint();

        $results = [];
        $failed = false;
        $chunks = array_chunk($requests, $this->concurrency(), true);
        // 1 区切りにかける最短の秒数。これより早く終わったら次の区切りまで待ち、1 秒あたりの上限を超えない
        $minChunkSeconds = $this->concurrency() / $this->requestsPerSecond();

        foreach ($chunks as $n => $chunk) {
            // 失敗した区切りの後は送らない（全体は失敗になるので、送っても費用と上限を使うだけ）
            if ($failed) {
                foreach (array_keys($chunk) as $i) {
                    $results[$i] = new DecisionNotSent;
                }

                continue;
            }

            $startedAt = microtime(true);
            $pooled = Http::pool(function (Pool $pool) use ($chunk, $apiKey, $url, $target, $model) {
                foreach ($chunk as $i => $request) {
                    $pool->as((string) $i)
                        ->withToken($apiKey)
                        ->acceptJson()
                        ->timeout($target->timeout)
                        ->post($url, $this->body($request, $model));
                }
            });

            foreach ($chunk as $i => $request) {
                $results[$i] = $this->settle($pooled[$i] ?? null, $request, $model);
                $failed = $failed || $results[$i] instanceof \Throwable;
            }

            $wait = $minChunkSeconds - (microtime(true) - $startedAt);
            if (! $failed && $n < count($chunks) - 1 && $wait > 0) {
                Sleep::usleep((int) ($wait * 1_000_000));
            }
        }

        return $results;
    }

    private function model(AiTarget $target): string
    {
        if (blank($target->model)) {
            throw new \InvalidArgumentException('Jev はモデル名の指定が必要です（例: jev-1.13.0）');
        }

        return $target->model;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(DecisionRequest $request, string $model): array
    {
        return [
            'model' => $model,
            'state' => $request->state,
            'questions' => $request->questionsPayload(),
        ];
    }

    private function endpoint(): string
    {
        $baseUrl = config('ai.providers.jev.base_url');
        if (blank($baseUrl)) {
            throw new \RuntimeException('Jev API の接続先（ai.providers.jev.base_url）が設定されていません');
        }

        return rtrim($baseUrl, '/').'/systemone';
    }

    private function requestsPerSecond(): int
    {
        $value = config('ai.decision.requests_per_second');

        if (! is_numeric($value) || (int) $value <= 0) {
            throw new \RuntimeException('config/ai.php の decision.requests_per_second が設定されていません');
        }

        return (int) $value;
    }

    private function concurrency(): int
    {
        $value = config('ai.decision.concurrency');

        if (! is_numeric($value) || (int) $value <= 0) {
            throw new \RuntimeException('config/ai.php の decision.concurrency が設定されていません');
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(AiTarget $target, array $body): Response
    {
        // キーは引数に載せない。例外のスタックトレースに値が出るため
        $apiKey = $this->keys->for(AiProvider::Jev);
        $url = $this->endpoint();

        try {
            return Http::withToken($apiKey)
                ->acceptJson()
                ->timeout($target->timeout)
                ->post($url, $body);
        } catch (ConnectionException $e) {
            throw $this->connectionFailed($e);
        }
    }

    /** pool の 1 要素を decide() と同じ基準で評価し、失敗は投げずに例外オブジェクトとして返す */
    private function settle(mixed $result, DecisionRequest $request, string $model): DecisionResponse|\Throwable
    {
        if ($result instanceof ConnectionException) {
            return $this->connectionFailed($result);
        }

        if (! $result instanceof Response) {
            return $result instanceof \Throwable ? $result : new \LogicException('判定の一括実行で応答を受け取れなかった要素があります');
        }

        try {
            return $this->evaluate($result, $request, $model);
        } catch (\Throwable $e) {
            return $e;
        }
    }

    private function connectionFailed(ConnectionException $e): \RuntimeException
    {
        return new \RuntimeException('Jev API に接続できませんでした: '.$this->redact($e->getMessage()), 0, $e);
    }

    /** 1 件の応答を評価する。HTTP エラー・JSON でない本文は RuntimeException、形の崩れは parse の AiRequestFailed */
    private function evaluate(Response $response, DecisionRequest $request, string $model): DecisionResponse
    {
        if ($response->failed()) {
            throw new \RuntimeException(
                'Jev API エラー（HTTP '.$response->status().'）: '.$this->redact($this->errorMessage($response)).$this->hint($response->status()),
            );
        }

        $json = $response->json();

        // 2xx でも本文が JSON でないことがある（base_url をプロキシ等に向けた場合）。型エラーではなく診断できる文言にする
        if (! is_array($json)) {
            throw new \RuntimeException(
                'Jev API の応答が JSON ではありません（HTTP '.$response->status().'）。接続先（ai.providers.jev.base_url）を確認してください: '
                .$this->excerpt($response->body()),
            );
        }

        return $this->parse($json, $request, $model);
    }

    /**
     * エラー本文は {"error": {"message": "..."}} を想定。文字列だけ・本文なしでも文言を組めるようにする。
     * 想定外の形の本文は先頭だけ載せる（契約が未検証のうちは理由を捨てない）
     */
    private function errorMessage(Response $response): string
    {
        $message = $response->json('error.message');
        if (is_string($message) && $message !== '') {
            return $message;
        }

        $error = $response->json('error');
        if (is_string($error) && $error !== '') {
            return $error;
        }

        $body = trim($response->body());

        return $body === '' ? '（error.message なし）' : '（error.message なし）本文: '.$this->excerpt($body);
    }

    /** 本文の先頭だけを返す。切り詰めでキーが途中で切れると伏せられないので、伏せてから切る */
    private function excerpt(string $body): string
    {
        return mb_strimwidth($this->redact($body), 0, self::BODY_EXCERPT, '…');
    }

    private function hint(int $status): string
    {
        return match ($status) {
            401 => '（TYPESAFE_API_KEY を確認してください）',
            429 => '（レート制限です。時間をおいて再実行してください）',
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function parse(array $json, DecisionRequest $request, string $model): DecisionResponse
    {
        $usage = $json['usage'] ?? null;
        $tokens = is_array($usage) ? new TokenUsage(
            input: $this->int($usage['input_tokens'] ?? null),
            output: $this->int($usage['output_tokens'] ?? null),
            raw: $usage,
        ) : null;

        $answers = $json['answers'] ?? null;
        if (! is_array($answers)) {
            throw new AiRequestFailed('Jev API の応答に answers がありません', AiProvider::Jev, $model, $tokens);
        }

        // こちらの質問に無い回答は読まない（余分な回答は無視する）
        $parsed = [];
        foreach ($request->questions as $question) {
            $id = $question->id();
            $answer = $answers[$id] ?? null;

            if (! is_array($answer)) {
                throw new AiRequestFailed("Jev API の応答に質問 {$id} の回答がありません", AiProvider::Jev, $model, $tokens);
            }

            $expected = $question->toPayload()['type'];
            $actual = $answer['type'] ?? null;
            if ($actual !== $expected) {
                $shown = is_string($actual) ? $actual : '（なし）';

                throw new AiRequestFailed("Jev API の回答 {$id} の型が質問と食い違います（質問: {$expected} / 回答: {$shown}）", AiProvider::Jev, $model, $tokens);
            }

            try {
                $parsed[$id] = match (true) {
                    $question instanceof ChoiceQuestion => $this->choice($answer),
                    $question instanceof ScoreQuestion => $this->score($answer, $question),
                    $question instanceof NoulQuestion => new NoulAnswer($this->float($answer, 'noul')),
                    default => throw new \LogicException('未対応の質問の型です: '.$question::class),
                };
            } catch (\UnexpectedValueException $e) {
                throw new AiRequestFailed("Jev API の回答 {$id} が不正です: {$e->getMessage()}", AiProvider::Jev, $model, $tokens, $e);
            }
        }

        $returnedModel = $json['model'] ?? null;

        return new DecisionResponse($parsed, $tokens, AiProvider::Jev, is_string($returnedModel) && $returnedModel !== '' ? $returnedModel : $model);
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function choice(array $answer): ChoiceAnswer
    {
        $choice = $answer['choice'] ?? null;
        if (! is_string($choice)) {
            throw new \UnexpectedValueException('choice がありません');
        }

        $probabilities = $answer['probabilities'] ?? null;
        if (! is_array($probabilities) || $probabilities === []) {
            throw new \UnexpectedValueException('probabilities がありません');
        }

        $normalized = [];
        foreach ($probabilities as $option => $value) {
            $normalized[(string) $option] = $this->number($value, "probabilities.{$option}");
        }

        return new ChoiceAnswer($choice, $normalized, $this->float($answer, 'confidence'));
    }

    /**
     * probabilities はリストか、段階名を鍵にした連想配列のどちらで返るか未確定なので、両方を levels の並びのリストにそろえる。
     *
     * @param  array<string, mixed>  $answer
     */
    private function score(array $answer, ScoreQuestion $question): ScoreAnswer
    {
        $probabilities = $answer['probabilities'] ?? null;
        if (! is_array($probabilities) || $probabilities === []) {
            throw new \UnexpectedValueException('probabilities がありません');
        }

        if (array_is_list($probabilities)) {
            $ordered = $probabilities;
        } else {
            $returned = array_map(strval(...), array_keys($probabilities));
            if (array_diff($returned, $question->levels) !== [] || array_diff($question->levels, $returned) !== []) {
                throw new \UnexpectedValueException('probabilities の鍵が段階名（levels）と一致しません');
            }
            $ordered = array_map(fn (string $level) => $probabilities[$level], $question->levels);
        }

        return new ScoreAnswer(
            $this->float($answer, 'score'),
            array_map(fn (mixed $value) => $this->number($value, 'probabilities'), $ordered),
            $this->float($answer, 'confidence'),
        );
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function float(array $answer, string $key): float
    {
        if (! array_key_exists($key, $answer)) {
            throw new \UnexpectedValueException("{$key} がありません");
        }

        return $this->number($answer[$key], $key);
    }

    /** JSON の数値だけを受ける（数字の文字列は形の崩れとして扱う） */
    private function number(mixed $value, string $label): float
    {
        if (! is_int($value) && ! is_float($value)) {
            throw new \UnexpectedValueException("{$label} が数値ではありません");
        }

        return (float) $value;
    }

    /** 無い欄は null（0 で埋めない） */
    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /** エラー本文などに含まれるキーを伏せる。キーの形式は公開されていないので、設定されている値で伏せる */
    private function redact(string $text): string
    {
        return $this->keys->redact(AiProvider::Jev, $text);
    }
}
