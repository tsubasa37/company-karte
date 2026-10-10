<?php

namespace App\Services\Ai\Driver;

use App\Enums\AiProvider;
use App\Services\Ai\ApiKeys;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Anthropic Messages API（POST /messages）で構造化出力を取る。
 *
 * 構造化出力は output_config.format（json_schema）を使う。ツール強制（tool_choice: tool / any）でも
 * JSON は取れるが、手動の拡張思考（thinking.type=enabled）と併用できず、Fable 5.1 / Mythos 5.1 は
 * ツール強制自体を 400 で拒否するため使わない。output_config.format は思考と併用でき、
 * 文法は思考ブロックには掛からない（結果テキストだけを縛る）。
 * ベータヘッダ（structured-outputs-2025-11-13）は GA 済みで不要なので送らない。
 * 確認 2026-09-03: https://platform.claude.com/docs/en/build-with-claude/structured-outputs
 * ・ https://platform.claude.com/docs/en/api/messages ・ /docs/en/build-with-claude/thinking
 * ・ /docs/en/build-with-claude/extended-thinking ・ /docs/en/api/errors
 */
class AnthropicDriver implements AiDriver
{
    /** Anthropic のキー形式（sk-ant-api…）。エラー本文に載る断片を伏せる */
    private const KEY_PATTERN = '/sk-ant-[A-Za-z0-9_-]+/';

    /** JSON でない本文を文言に載せる長さ（全文をログ・analysis_steps.error に流さない） */
    private const BODY_EXCERPT = 200;

    /** 正常終了。これ以外（max_tokens / refusal / model_context_window_exceeded 等）は課金済みの失敗 */
    private const STOP_OK = 'end_turn';

    /** 拡張思考の budget_tokens の下限（公式: 1,024 未満は API が拒否し、max_tokens 未満である必要がある） */
    private const MIN_BUDGET_TOKENS = 1024;

    public function __construct(private readonly ApiKeys $keys) {}

    public function run(AiRequest $request): AiResponse
    {
        if (blank($request->model)) {
            throw new \InvalidArgumentException('Anthropic API はモデル名の指定が必要です');
        }

        $response = $this->send($request, $this->body($request));

        if ($response->failed()) {
            throw new \RuntimeException($this->errorMessage($response));
        }

        $json = $response->json();

        // 2xx でも本文が JSON でないことがある（base_url をプロキシ等に向けた場合）。型エラーではなく診断できる文言にする
        if (! is_array($json)) {
            throw new \RuntimeException(
                'Anthropic API の応答が JSON ではありません（HTTP '.$response->status().'）。接続先（ai.providers.anthropic.base_url）を確認してください: '
                .$this->redact(mb_strimwidth($response->body(), 0, self::BODY_EXCERPT, '…')),
            );
        }

        return $this->parse($json, $request->model);
    }

    /**
     * 正規スキーマ（OpenAI strict 形式）をそのまま渡す。additionalProperties:false・required・
     * type 配列の "null" はいずれも対応済みの書き方（上限は AiSchemaLimitsTest で固定している）。
     *
     * @return array<string, mixed>
     */
    private function body(AiRequest $request): array
    {
        $maxTokens = $this->maxTokens();
        $reasoning = $this->reasoning($request->options, $maxTokens);

        $body = [
            'model' => $request->model,
            // max_tokens は必須。思考トークンもこの上限に含まれる
            'max_tokens' => $maxTokens,
            'messages' => [['role' => 'user', 'content' => $request->prompt]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $request->schema]],
        ];

        if ($reasoning['thinking'] !== []) {
            $body['thinking'] = $reasoning['thinking'];
        }

        // effort は thinking の中ではなく output_config に置く（公式の移行例。thinking に effort は無い）
        if ($reasoning['effort'] !== null) {
            $body['output_config']['effort'] = $reasoning['effort'];
        }

        return $body;
    }

    /**
     * 推論の制御。adaptive（4.6 以降。深さは output_config.effort）と enabled（4.6 以前。budget_tokens で指定）は
     * 対応する世代が違い、取り違えると API が 400 を返す（4.6 だけが両対応で、enabled は非推奨ながら動作する）。
     * 設定されているものだけ送り、噛み合わない組合せは片方を黙って選ばずに落とす。
     * 確認 2026-09-03: https://platform.claude.com/docs/en/build-with-claude/thinking-troubleshooting
     *
     * @param  array<string, mixed>  $options
     * @return array{thinking: array<string, mixed>, effort: string|null}
     */
    private function reasoning(array $options, int $maxTokens): array
    {
        $mode = $options['thinking_mode'] ?? null;
        $effort = $options['effort'] ?? null;
        $budget = $options['budget_tokens'] ?? null;

        if (blank($mode)) {
            if (filled($effort) || filled($budget)) {
                throw new \InvalidArgumentException(
                    'Anthropic の effort / budget_tokens は thinking_mode を選んだときだけ指定できます。設定画面で thinking_mode を選んでください',
                );
            }

            return ['thinking' => [], 'effort' => null];
        }

        return match ((string) $mode) {
            'adaptive' => ['thinking' => ['type' => 'adaptive'], 'effort' => $this->adaptiveEffort($effort, $budget)],
            'enabled' => ['thinking' => ['type' => 'enabled', 'budget_tokens' => $this->budgetTokens($budget, $effort, $maxTokens)], 'effort' => null],
            default => throw new \InvalidArgumentException("Anthropic の thinking_mode は adaptive か enabled です（受け取った値: {$mode}）"),
        };
    }

    private function adaptiveEffort(mixed $effort, mixed $budget): ?string
    {
        if (filled($budget)) {
            throw new \InvalidArgumentException(
                'Anthropic の budget_tokens は thinking_mode=enabled（4.6 以前）専用です。adaptive では effort で深さを指定してください',
            );
        }

        return filled($effort) ? (string) $effort : null;
    }

    private function budgetTokens(mixed $budget, mixed $effort, int $maxTokens): int
    {
        if (filled($effort)) {
            throw new \InvalidArgumentException(
                'Anthropic の effort は thinking_mode=adaptive（4.6 以降）専用です。enabled では budget_tokens で思考量を指定してください',
            );
        }

        if (! is_numeric($budget)) {
            throw new \InvalidArgumentException('Anthropic の thinking_mode=enabled には budget_tokens（整数）の指定が必要です');
        }

        $value = (int) $budget;

        if ($value < self::MIN_BUDGET_TOKENS || $value >= $maxTokens) {
            throw new \InvalidArgumentException(
                'Anthropic の budget_tokens は '.self::MIN_BUDGET_TOKENS."以上 かつ max_tokens（{$maxTokens}）未満にしてください（受け取った値: {$value}）",
            );
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(AiRequest $request, array $body): Response
    {
        // キーは引数に載せない。例外のスタックトレースに値が出るため
        $apiKey = $this->apiKey();

        $baseUrl = config('ai.providers.anthropic.base_url');
        if (blank($baseUrl)) {
            throw new \RuntimeException('Anthropic API の接続先（ai.providers.anthropic.base_url）が設定されていません');
        }

        $version = config('ai.providers.anthropic.version');
        if (blank($version)) {
            throw new \RuntimeException('Anthropic API のバージョン（ai.providers.anthropic.version）が設定されていません');
        }

        try {
            return Http::withHeaders(['x-api-key' => $apiKey, 'anthropic-version' => $version])
                ->acceptJson()
                ->timeout($request->timeout)
                ->post(rtrim($baseUrl, '/').'/messages', $body);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('Anthropic API に接続できませんでした: '.$this->redact($e->getMessage()), 0, $e);
        }
    }

    /**
     * 4xx / 5xx の文言。本文の error.type（rate_limit_error 等）と error.message だけを載せる。
     */
    private function errorMessage(Response $response): string
    {
        $type = $response->json('error.type');
        $message = $response->json('error.message');
        $hint = match ($response->status()) {
            429 => '（レート上限・利用枠の超過です。時間をおいて実行してください）',
            529 => '（Anthropic 側が混雑しています。時間をおいて実行してください）',
            default => '',
        };

        return 'Anthropic API エラー（HTTP '.$response->status().' '.(is_string($type) ? $type : 'type 不明').'）'.$hint.': '
            .$this->redact(is_string($message) ? $message : '（error.message なし）');
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function parse(array $json, string $model): AiResponse
    {
        $usage = $json['usage'] ?? null;
        $tokens = $this->tokens(is_array($usage) ? $usage : null);
        $stop = $json['stop_reason'] ?? null;

        if ($stop === 'refusal') {
            $explanation = $json['stop_details']['explanation'] ?? '';

            throw new AiRequestFailed('Anthropic API が応答を拒否しました: '.$explanation, AiProvider::Anthropic, $model, $tokens);
        }

        if (filled($stop) && $stop !== self::STOP_OK) {
            throw new AiRequestFailed("Anthropic API の応答が完了しませんでした（{$stop}）", AiProvider::Anthropic, $model, $tokens);
        }

        $text = $this->outputText($json);
        if ($text === '') {
            throw new AiRequestFailed('Anthropic API の応答にテキスト出力（content[].text）がありません', AiProvider::Anthropic, $model, $tokens);
        }

        $data = json_decode($text, true);
        if (! is_array($data)) {
            throw new AiRequestFailed('Anthropic API の応答が JSON ではありません: '.$this->excerpt($text), AiProvider::Anthropic, $model, $tokens);
        }

        return new AiResponse($data, $tokens, AiProvider::Anthropic, $model);
    }

    /**
     * 結果のテキスト。thinking / redacted_thinking ブロックは思考であって結果ではないので混ぜない。
     *
     * @param  array<string, mixed>  $json
     */
    private function outputText(array $json): string
    {
        // 形の違う応答（content が配列でない・要素が配列でない）はテキスト無しとして扱い、
        // 呼び出し元の AiRequestFailed に寄せる。警告由来の ErrorException だと課金済みの usage が残らない
        $content = $json['content'] ?? null;
        $text = '';
        foreach (is_array($content) ? $content : [] as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'text') {
                continue;
            }
            if (is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        return $text;
    }

    /**
     * usage を共通の意味に正規化する。
     *
     * 公式: input_tokens はキャッシュ分を含まない（総入力 = input_tokens + cache_read + cache_creation）ため足し合わせる。
     * thinking_tokens は output_tokens の内数（output_tokens が課金の正）なので加算しない。
     *
     * @param  array<string, mixed>|null  $usage
     */
    private function tokens(?array $usage): ?TokenUsage
    {
        if ($usage === null) {
            return null;
        }

        $input = $this->int($usage['input_tokens'] ?? null);
        $cached = $this->int($usage['cache_read_input_tokens'] ?? null);
        $cacheWrite = $this->int($usage['cache_creation_input_tokens'] ?? null);

        return new TokenUsage(
            input: $input === null ? null : $input + ($cached ?? 0) + ($cacheWrite ?? 0),
            cached: $cached,
            cacheWrite: $cacheWrite,
            output: $this->int($usage['output_tokens'] ?? null),
            reasoning: $this->int($usage['output_tokens_details']['thinking_tokens'] ?? null),
            raw: $usage,
        );
    }

    /** 無い欄は null（0 で埋めない） */
    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function maxTokens(): int
    {
        $maxTokens = config('ai.providers.anthropic.max_tokens');

        if (! is_numeric($maxTokens) || (int) $maxTokens <= 0) {
            throw new \RuntimeException('Anthropic API の max_tokens（ai.providers.anthropic.max_tokens）が設定されていません');
        }

        return (int) $maxTokens;
    }

    /** キーの置き場所は環境変数の1本だけ。未設定の文言（変数名つき）は ApiKeys が出す */
    private function apiKey(): string
    {
        return $this->keys->for(AiProvider::Anthropic);
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
        return $this->keys->redact(AiProvider::Anthropic, preg_replace(self::KEY_PATTERN, 'sk-ant-***', $text) ?? $text);
    }
}
