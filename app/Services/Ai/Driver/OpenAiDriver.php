<?php

namespace App\Services\Ai\Driver;

use App\Enums\AiProvider;
use App\Services\Ai\ApiKeys;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI Responses API（POST /responses）で構造化出力を取る。
 *
 * - スキーマは正規形（strict 形式）をそのまま text.format に渡す
 * - store:false でレスポンスを OpenAI 側に保持・取得可能にしない（送信データの保持は API データポリシーに従う）
 * - HTTP エラー・JSON でない本文は日本語文言にする。Http の例外はそのまま再送出しない
 * - 打ち切り・refusal は課金済みなので usage 付きの AiRequestFailed にする
 * - リトライはしない（フェイルファスト。失敗したステップからの再開で復旧する）
 */
class OpenAiDriver implements AiDriver
{
    /** 構造化出力の名前（API 必須項目。任意の識別子） */
    private const FORMAT_NAME = 'result';

    /** OpenAI のキー形式。エラー本文に載る断片を伏せる */
    private const KEY_PATTERN = '/sk-[A-Za-z0-9_-]+/';

    /** JSON でない本文を文言に載せる長さ（全文をログ・analysis_steps.error に流さない） */
    private const BODY_EXCERPT = 200;

    public function __construct(private readonly ApiKeys $keys) {}

    public function run(AiRequest $request): AiResponse
    {
        if (blank($request->model)) {
            throw new \InvalidArgumentException('OpenAI API はモデル名の指定が必要です');
        }

        $response = $this->send($request, $this->body($request));

        if ($response->failed()) {
            $message = $response->json('error.message');
            // 429 はレート上限・利用枠の超過。他のドライバと同じく原因が分かる日本語を足す
            $hint = $response->status() === 429 ? '（レート上限・利用枠の超過です。時間をおいて実行してください）' : '';

            throw new \RuntimeException(
                'OpenAI API エラー（HTTP '.$response->status().'）: '.$this->redact(is_string($message) ? $message : '（error.message なし）').$hint,
            );
        }

        $json = $response->json();

        // 2xx でも本文が JSON でないことがある（base_url をプロキシ等に向けた場合）。型エラーではなく診断できる文言にする
        if (! is_array($json)) {
            throw new \RuntimeException(
                'OpenAI API の応答が JSON ではありません（HTTP '.$response->status().'）。接続先（ai.providers.openai.base_url）を確認してください: '
                .$this->redact(mb_strimwidth($response->body(), 0, self::BODY_EXCERPT, '…')),
            );
        }

        return $this->parse($json, $request->model);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(AiRequest $request): array
    {
        $body = [
            'model' => $request->model,
            'input' => [['role' => 'user', 'content' => $request->prompt]],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => self::FORMAT_NAME,
                    'schema' => $request->schema,
                    'strict' => true,
                ],
            ],
            'store' => false,
            // 出力上限（推論トークン込み）。送らないと長い生成が timeout で切れ、usage を取れないまま
            // 課金だけが残る。上限に当たった場合は status=incomplete + usage 付きで返る
            'max_output_tokens' => $this->maxOutputTokens(),
        ];

        // 推論強度は Tier 別の options に入っているときだけ送る（非対応モデルに送ると 400 になる）
        $effort = $request->options['reasoning_effort'] ?? null;
        if (filled($effort)) {
            $body['reasoning'] = ['effort' => $effort];
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(AiRequest $request, array $body): Response
    {
        // キーは引数に載せない。例外のスタックトレースに値が出るため
        $apiKey = $this->apiKey();

        $baseUrl = config('ai.providers.openai.base_url');
        if (blank($baseUrl)) {
            throw new \RuntimeException('OpenAI API の接続先（ai.providers.openai.base_url）が設定されていません');
        }

        try {
            return Http::withToken($apiKey)
                ->acceptJson()
                ->timeout($request->timeout)
                ->post(rtrim($baseUrl, '/').'/responses', $body);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('OpenAI API に接続できませんでした: '.$this->redact($e->getMessage()), 0, $e);
        }
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function parse(array $json, string $model): AiResponse
    {
        $usage = $json['usage'] ?? null;
        $tokens = $this->tokens(is_array($usage) ? $usage : null);

        // 完了以外（max_output_tokens / content_filter 等）は課金済みの失敗。
        // status=failed のときだけ理由が error.message に入る（incomplete_details には入らない）ので併せて載せる
        if (($json['status'] ?? null) !== 'completed') {
            $reason = $json['incomplete_details']['reason'] ?? ($json['status'] ?? '不明');
            $error = $json['error']['message'] ?? null;
            $detail = is_string($error) ? '：'.$this->redact($error) : '';

            throw new AiRequestFailed("OpenAI API の応答が完了しませんでした（{$reason}）{$detail}", AiProvider::OpenAi, $model, $tokens);
        }

        // 出力が複数の output_text パートに分かれることがあるので、上書きせず連結する（Gemini / Anthropic と同じ）。
        // 形の違う応答（配列でない output / content / 要素）はテキスト無しとして扱い、下の AiRequestFailed に寄せる
        // （警告由来の ErrorException だと課金済みの usage が残らない）
        $output = $json['output'] ?? null;
        $text = '';
        foreach (is_array($output) ? $output : [] as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }
            $content = $item['content'] ?? null;
            foreach (is_array($content) ? $content : [] as $part) {
                if (! is_array($part)) {
                    continue;
                }
                if (($part['type'] ?? null) === 'refusal') {
                    throw new AiRequestFailed('OpenAI API が応答を拒否しました: '.($part['refusal'] ?? ''), AiProvider::OpenAi, $model, $tokens);
                }
                if (($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                }
            }
        }

        if ($text === '') {
            throw new AiRequestFailed('OpenAI API の応答にテキスト出力（output_text）がありません', AiProvider::OpenAi, $model, $tokens);
        }

        $data = json_decode($text, true);
        if (! is_array($data)) {
            throw new AiRequestFailed('OpenAI API の応答が JSON ではありません: '.$this->excerpt($text), AiProvider::OpenAi, $model, $tokens);
        }

        return new AiResponse($data, $tokens, AiProvider::OpenAi, $model);
    }

    /**
     * usage を共通の意味に正規化する（input は cached を含む総入力、output は reasoning を含む総出力）。
     *
     * input_tokens_details には cached_tokens と cache_write_tokens の両方がある
     * （確認 2026-09-03: https://github.com/openai/openai-python/blob/main/src/openai/types/responses/response_usage.py
     *  の InputTokensDetails。cache_write_tokens = "The number of input tokens that were written to the cache"）。
     * キャッシュ書き込みは GPT-5.6 以降で入力単価の 1.25 倍として課金されるため、単価表と揃えて読み取る
     * （確認 2026-09-03: https://platform.openai.com/docs/guides/prompt-caching の Cache write charge）。
     * 5.5 以前は書き込みに追加課金が無く、単価表にも cache_write を置いていないので AiPricing 側で input 単価になる。
     *
     * @param  array<string, mixed>|null  $usage
     */
    private function tokens(?array $usage): ?TokenUsage
    {
        if ($usage === null) {
            return null;
        }

        return new TokenUsage(
            input: $this->int($usage['input_tokens'] ?? null),
            cached: $this->int($usage['input_tokens_details']['cached_tokens'] ?? null),
            cacheWrite: $this->int($usage['input_tokens_details']['cache_write_tokens'] ?? null),
            output: $this->int($usage['output_tokens'] ?? null),
            reasoning: $this->int($usage['output_tokens_details']['reasoning_tokens'] ?? null),
            raw: $usage,
        );
    }

    /** 無い欄は null（0 で埋めない） */
    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /** 出力上限（推論トークン込み）。壊れた設定で上限なしのまま投げない */
    private function maxOutputTokens(): int
    {
        $max = config('ai.providers.openai.max_output_tokens');

        if (! is_numeric($max) || (int) $max <= 0) {
            throw new \RuntimeException('OpenAI API の出力上限（ai.providers.openai.max_output_tokens）が設定されていません');
        }

        return (int) $max;
    }

    /** キーの置き場所は環境変数の1本だけ。未設定の文言（変数名つき）は ApiKeys が出す */
    private function apiKey(): string
    {
        return $this->keys->for(AiProvider::OpenAi);
    }

    /** 例外文言に載せる本文は先頭だけにする（全文だと DB と画面に数十KBが流れる） */
    private function excerpt(string $text): string
    {
        return mb_strimwidth($this->redact($text), 0, self::BODY_EXCERPT, '…');
    }

    /** 401 本文などに含まれるキーの断片を伏せる（analysis_steps.error やログに残るため） */
    private function redact(string $text): string
    {
        // 形式のパターンと、実際に設定されている値の両方で伏せる（キーの形式は社の都合で変わる）
        return $this->keys->redact(AiProvider::OpenAi, preg_replace(self::KEY_PATTERN, 'sk-***', $text) ?? $text);
    }
}
