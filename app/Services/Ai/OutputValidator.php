<?php

namespace App\Services\Ai;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/**
 * AI の応答を正規スキーマ（OpenAI strict 形式）で検証する。全ドライバ共通で AiRunner が通す。
 * 不一致は「どの path が何で違うか」を載せた例外にし、欠けた項目を黙って通さない。
 */
class OutputValidator
{
    /** 例外文言に載せる不一致の上限。1つ目で止めると直す手掛かりが足りないため複数拾う */
    private const MAX_ERRORS = 5;

    /**
     * 応答の大きさの上限。スキーマ（各社の方言差があるため maxLength / maxItems は送らない）の外側で、
     * アプリ側だけで検査する。通常の分析出力（実測は自由記述1項目で最長でも数百字・配列は数十件）より
     * 十分大きく、注入で読み込ませたファイル内容の丸写しのような異常だけを弾く。
     * 分析の材料や判断を削る意図の値ではないので、正常な出力が当たるほど小さくしないこと。
     */
    private const MAX_STRING_CHARS = 6000;

    private const MAX_ARRAY_ITEMS = 500;

    private const MAX_TOTAL_BYTES = 300_000;

    /**
     * 応答全体・文字列1項目・配列1つの大きさが上限に収まるかを検査する。
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AiOutputMismatchException
     */
    public function assertBounded(array $data): void
    {
        $bytes = strlen(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        if ($bytes > self::MAX_TOTAL_BYTES) {
            throw new AiOutputMismatchException(
                "AI の出力が大きすぎます（{$bytes} バイト。上限 ".self::MAX_TOTAL_BYTES.' バイト）'
            );
        }

        $this->walk($data, '');
    }

    private function walk(mixed $value, string $path): void
    {
        if (is_string($value)) {
            $length = mb_strlen($value);
            if ($length > self::MAX_STRING_CHARS) {
                throw new AiOutputMismatchException(
                    'AI の出力が大きすぎます（'.($path === '' ? '/' : $path).": {$length} 文字。1項目の上限 ".self::MAX_STRING_CHARS.' 文字）'
                );
            }

            return;
        }

        if (! is_array($value)) {
            return;
        }

        if (array_is_list($value) && count($value) > self::MAX_ARRAY_ITEMS) {
            throw new AiOutputMismatchException(
                'AI の出力が大きすぎます（'.($path === '' ? '/' : $path).': '.count($value).' 件。配列の上限 '.self::MAX_ARRAY_ITEMS.' 件）'
            );
        }

        foreach ($value as $key => $item) {
            $this->walk($item, $path.'/'.$key);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $schema
     *
     * @throws AiOutputMismatchException
     */
    public function assert(array $data, array $schema): void
    {
        $validator = new Validator(null, self::MAX_ERRORS, false);
        $result = $validator->validate($this->toJson($data), $this->toJson($schema));

        if ($result->isValid()) {
            return;
        }

        $lines = [];
        foreach ((new ErrorFormatter)->format($result->error(), true, $this->describe(...)) as $path => $messages) {
            $lines[] = ($path === '' ? '/' : $path).': '.implode(' / ', $messages);
        }

        throw new AiOutputMismatchException('AI の出力が正規スキーマと一致しません: '.implode('; ', $lines));
    }

    /**
     * PHP 配列を JSON と同じ形（連想配列 → object、リスト → array）に戻す。
     * json_decode(…, true) で受けた応答は object と array の区別が消えているため、検証前に復元する。
     */
    private function toJson(array $value): mixed
    {
        return json_decode(
            json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** 主要な不一致を日本語にする。その他はライブラリの文言をそのまま使う */
    private function describe(ValidationError $error, ?string $message = null): string
    {
        $args = $error->args();

        return match ($error->keyword()) {
            'required' => '必須項目 '.implode(', ', $args['missing']).' が欠けています',
            'type' => '型が '.$this->typeLabel($args['expected']).' ではありません（実際: '.$args['type'].'）',
            'additionalProperties' => 'スキーマに無い項目 '.implode(', ', $args['properties']).' があります',
            'enum' => 'enum に無い値です（'.json_encode($error->data()->value(), JSON_UNESCAPED_UNICODE).'）',
            default => (new ErrorFormatter)->formatErrorMessage($error, $message),
        };
    }

    /**
     * @param  string|array<int, string>  $expected
     */
    private function typeLabel(string|array $expected): string
    {
        return is_array($expected) ? implode('|', $expected) : $expected;
    }
}
