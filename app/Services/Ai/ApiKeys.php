<?php

namespace App\Services\Ai;

use App\Enums\AiProvider;

/**
 * API キーの読み取り口。置き場所は .env の1本だけで、DB には持たない
 * （EDINET_API_KEY / GBIZINFO_API_TOKEN と同じ扱いに揃える）。
 *
 * 未設定はここで日本語の例外にして止める。文言には .env の変数名を出し、
 * 「どこに何を書けばよいか」が分かるようにする（自動で別の実行先に落とさない）。
 */
class ApiKeys
{
    public function has(AiProvider $provider): bool
    {
        return filled($this->raw($provider));
    }

    /**
     * @throws \RuntimeException 未設定のとき（変数名を文言に含める）
     */
    public function for(AiProvider $provider): string
    {
        $key = $this->raw($provider);

        if (blank($key)) {
            $name = $provider->apiKeyEnvName();

            // codex（キーを持たない実行先）を渡すのは呼び出し側の誤り
            if ($name === null) {
                throw new \LogicException($provider->label().' は API キーを使いません');
            }

            throw new \RuntimeException(".env の {$name} が未設定です（{$provider->label()} を使うには設定してください）");
        }

        return $key;
    }

    /**
     * 外に出す文言から、設定されているキーそのものを伏せる。
     *
     * 各ドライバは「sk-…」のような形式のパターンでも伏せているが、キーの形式は社の都合で変わる。
     * 実際の値で置き換えるこちらは形式が変わっても効くので、両方を通す。
     */
    public function redact(AiProvider $provider, string $text): string
    {
        $key = $this->raw($provider);

        if ($key === null) {
            return $text;
        }

        return str_replace($key, '***', $text);
    }

    private function raw(AiProvider $provider): ?string
    {
        if ($provider->apiKeyEnvName() === null) {
            return null;
        }

        $key = config("ai.providers.{$provider->value}.api_key");

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }
}
