<?php

namespace App\Services\Ai\Driver;

use App\Enums\AiProvider;
use App\Services\Ai\ApiKeys;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Gemini generateContent API（POST /models/{model}:generateContent）で構造化出力を取る。
 *
 * 新しい Interactions API ではなく generateContent を選んだ理由は2つ。(1) 応答の形が公式に全て書いてある
 * （finishReason / promptFeedback.blockReason / usageMetadata の各欄）。Interactions は安全性ブロックの
 * 表れ方が公式に無く、推論も thinking_level しか無い（2.5 系の thinkingBudget が無い）。(2) Interactions は
 * 既定で送信内容をサーバに保存する（store=true。有料 55 日 / 無料 1 日保持）。口コミ・面接メモを送るので避ける。
 * 確認 2026-09-03: https://ai.google.dev/api/generate-content ・ https://ai.google.dev/gemini-api/docs/interactions
 * ・ /docs/generate-content/structured-output ・ /docs/generate-content/thinking ・ /docs/generate-content/api-errors
 */
class GeminiDriver implements AiDriver
{
    /** Google API キーの形式。エラー本文に載る断片を伏せる */
    private const KEY_PATTERN = '/AIza[0-9A-Za-z_-]+/';

    /** JSON でない本文を文言に載せる長さ（全文をログ・analysis_steps.error に流さない） */
    private const BODY_EXCERPT = 200;

    /** 正常終了。これ以外（MAX_TOKENS / SAFETY / RECITATION 等）は課金済みの失敗 */
    private const FINISH_OK = 'STOP';

    public function __construct(private readonly ApiKeys $keys) {}

    public function run(AiRequest $request): AiResponse
    {
        if (blank($request->model)) {
            throw new \InvalidArgumentException('Gemini API はモデル名の指定が必要です');
        }

        $response = $this->send($request, $this->body($request));

        if ($response->failed()) {
            throw new \RuntimeException($this->errorMessage($response));
        }

        $json = $response->json();

        // 2xx でも本文が JSON でないことがある（base_url をプロキシ等に向けた場合）。型エラーではなく診断できる文言にする
        if (! is_array($json)) {
            throw new \RuntimeException(
                'Gemini API の応答が JSON ではありません（HTTP '.$response->status().'）。接続先（ai.providers.gemini.base_url）を確認してください: '
                .$this->redact(mb_strimwidth($response->body(), 0, self::BODY_EXCERPT, '…')),
            );
        }

        return $this->parse($json, $request->model);
    }

    /**
     * 正規スキーマ（OpenAI strict 形式）をそのまま渡す。JSON Schema モードは additionalProperties・required・
     * type 配列の "null" を受けるので変換しない（旧 responseSchema / responseJsonSchema は deprecated なので使わない）。
     *
     * @return array<string, mixed>
     */
    private function body(AiRequest $request): array
    {
        // 構造化出力は responseMimeType + responseJsonSchema（標準 JSON Schema をそのまま受ける）。
        // responseFormat.text.mimeType は文字列を受けず 400 になる（2026-09-03 に実 API で確認）
        $generationConfig = [
            'responseMimeType' => 'application/json',
            'responseJsonSchema' => $request->schema,
            // 出力上限（思考トークン込み）。送らないと長い生成が timeout で切れ、usageMetadata を
            // 取れないまま課金だけが残る。上限に当たった場合は finishReason=MAX_TOKENS + usage 付きで返る
            'maxOutputTokens' => $this->maxOutputTokens(),
        ];

        $thinking = $this->thinkingConfig($request->options);
        if ($thinking !== []) {
            $generationConfig['thinkingConfig'] = $thinking;
        }

        return [
            'contents' => [['role' => 'user', 'parts' => [['text' => $request->prompt]]]],
            'generationConfig' => $generationConfig,
        ];
    }

    /**
     * 推論の制御。thinkingLevel は Gemini 3 系、thinkingBudget は 2.5 系で、世代を取り違えると API がエラーを返す。
     * 設定されている方だけ送る（未設定ならモデルの既定）。両方あるのは設定の壊れなので、片方を黙って選ばず落とす。
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function thinkingConfig(array $options): array
    {
        $level = $options['thinking_level'] ?? null;
        $budget = $options['thinking_budget'] ?? null;

        if (filled($level) && filled($budget)) {
            throw new \InvalidArgumentException(
                'Gemini の thinking_level（Gemini 3 系）と thinking_budget（2.5 系）は同時に指定できません。設定画面でどちらか一方にしてください',
            );
        }

        if (filled($level)) {
            return ['thinkingLevel' => (string) $level];
        }

        // 0（思考オフ）・-1（動的）は意味のある値なので filled() で残る
        if (filled($budget)) {
            if (! is_numeric($budget)) {
                throw new \InvalidArgumentException('Gemini の thinking_budget は整数で指定してください（0 で思考オフ、-1 で動的）');
            }

            return ['thinkingBudget' => (int) $budget];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(AiRequest $request, array $body): Response
    {
        // キーは引数に載せない。例外のスタックトレースに値が出るため
        $apiKey = $this->apiKey();

        $baseUrl = config('ai.providers.gemini.base_url');
        if (blank($baseUrl)) {
            throw new \RuntimeException('Gemini API の接続先（ai.providers.gemini.base_url）が設定されていません');
        }

        $url = rtrim($baseUrl, '/').'/models/'.rawurlencode($request->model).':generateContent';

        try {
            return Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->acceptJson()
                ->timeout($request->timeout)
                ->post($url, $body);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('Gemini API に接続できませんでした: '.$this->redact($e->getMessage()), 0, $e);
        }
    }

    /**
     * 4xx / 5xx の文言。本文の error.status（RESOURCE_EXHAUSTED 等）と error.message だけを載せる。
     */
    private function errorMessage(Response $response): string
    {
        $status = $response->json('error.status');
        $message = $response->json('error.message');
        // 429 は RESOURCE_EXHAUSTED（RPM / TPM / 日次クォータ）。原因が分かる日本語を足す
        $hint = $response->status() === 429 ? '（レート上限・クォータ超過です。時間をおいて実行してください）' : '';

        return 'Gemini API エラー（HTTP '.$response->status().' '.(is_string($status) ? $status : 'status 不明').'）'.$hint.': '
            .$this->redact(is_string($message) ? $message : '（error.message なし）');
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function parse(array $json, string $model): AiResponse
    {
        $usage = $json['usageMetadata'] ?? null;
        $tokens = $this->tokens(is_array($usage) ? $usage : null);

        // 入力が弾かれた場合は candidates が空で promptFeedback だけ返る
        $blockReason = $json['promptFeedback']['blockReason'] ?? null;
        if (filled($blockReason)) {
            throw new AiRequestFailed("Gemini API が入力をブロックしました（{$blockReason}）", AiProvider::Gemini, $model, $tokens);
        }

        $candidate = $json['candidates'][0] ?? null;
        if (! is_array($candidate)) {
            throw new AiRequestFailed('Gemini API の応答に candidates がありません', AiProvider::Gemini, $model, $tokens);
        }

        $finishReason = $candidate['finishReason'] ?? null;
        if (filled($finishReason) && $finishReason !== self::FINISH_OK) {
            throw new AiRequestFailed("Gemini API の応答が完了しませんでした（{$finishReason}）", AiProvider::Gemini, $model, $tokens);
        }

        $text = $this->outputText($candidate);
        if ($text === '') {
            throw new AiRequestFailed('Gemini API の応答にテキスト出力（candidates[0].content.parts[].text）がありません', AiProvider::Gemini, $model, $tokens);
        }

        $data = json_decode($text, true);
        if (! is_array($data)) {
            throw new AiRequestFailed('Gemini API の応答が JSON ではありません: '.$this->excerpt($text), AiProvider::Gemini, $model, $tokens);
        }

        return new AiResponse($data, $tokens, AiProvider::Gemini, $model);
    }

    /**
     * 結果のテキスト。thought:true の part は思考の要約で結果ではないので混ぜない。
     *
     * @param  array<string, mixed>  $candidate
     */
    private function outputText(array $candidate): string
    {
        $content = $candidate['content'] ?? null;
        $parts = is_array($content) ? $content['parts'] ?? null : null;

        // 形の違う応答（parts が配列でない・要素が配列でない）はテキスト無しとして扱い、
        // 呼び出し元の AiRequestFailed に寄せる。警告由来の ErrorException だと課金済みの usage が残らない
        $text = '';
        foreach (is_array($parts) ? $parts : [] as $part) {
            if (! is_array($part) || ($part['thought'] ?? false) === true) {
                continue;
            }
            if (is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        return $text;
    }

    /**
     * usageMetadata を共通の意味に正規化する。
     *
     * output は candidatesTokenCount + thoughtsTokenCount（思考は candidates の外数で、料金は両者の和）。
     * cacheWrite は null（明示キャッシュは保存時間で課金され、書き込みトークンの欄が無い）。
     *
     * promptTokenCount は入力が必ず 1 トークン以上あるため 0 になることがなく、省略もされない。
     * これが無い usageMetadata は数えられていないので、0 で埋めず丸ごと null を返す
     * （0 埋めすると AiPricing が「推定費用 $0」という無かった数字を作る。トークン数が不明なら null にする）。
     *
     * @param  array<string, mixed>|null  $usage
     */
    private function tokens(?array $usage): ?TokenUsage
    {
        if ($usage === null || ! is_numeric($usage['promptTokenCount'] ?? null)) {
            return null;
        }

        $thoughts = $this->int($usage['thoughtsTokenCount'] ?? null);

        return new TokenUsage(
            input: (int) $usage['promptTokenCount'],
            cached: $this->int($usage['cachedContentTokenCount'] ?? null),
            cacheWrite: null,
            output: $this->int($usage['candidatesTokenCount'] ?? null) + $thoughts,
            reasoning: $thoughts,
            raw: $usage,
        );
    }

    /**
     * 省略が正常な欄（cachedContentTokenCount / thoughtsTokenCount / candidatesTokenCount）だけの読み方。
     * Gemini は値が 0 の欄を JSON から省くため、これらの欠損は「0 だった」を意味する。
     * promptTokenCount の欠損は意味が違う（= usage が取れていない）ので、ここでは扱わない。
     */
    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /** 出力上限（思考トークン込み）。壊れた設定で上限なしのまま投げない */
    private function maxOutputTokens(): int
    {
        $max = config('ai.providers.gemini.max_output_tokens');

        if (! is_numeric($max) || (int) $max <= 0) {
            throw new \RuntimeException('Gemini API の出力上限（ai.providers.gemini.max_output_tokens）が設定されていません');
        }

        return (int) $max;
    }

    /** キーの置き場所は環境変数の1本だけ。未設定の文言（変数名つき）は ApiKeys が出す */
    private function apiKey(): string
    {
        return $this->keys->for(AiProvider::Gemini);
    }

    /** 例外文言に載せる本文は先頭だけにする（全文だと DB と画面に数十KBが流れる） */
    private function excerpt(string $text): string
    {
        return mb_strimwidth($this->redact($text), 0, self::BODY_EXCERPT, '…');
    }

    /** エラー本文などに含まれるキーの断片を伏せる（analysis_steps.error やログに残るため） */
    private function redact(string $text): string
    {
        // 形式のパターンと、実際に設定されている値の両方で伏せる（キーの形式は社の都合で変わる）
        return $this->keys->redact(AiProvider::Gemini, preg_replace(self::KEY_PATTERN, 'AIza***', $text) ?? $text);
    }
}
