<?php

namespace App\Enums;

/**
 * AI の実行先。値は ai_settings / ai_provider_configs / ai_usages に保存するため文字列は変えない。
 */
enum AiProvider: string
{
    case Codex = 'codex';
    case OpenAi = 'openai';
    case Gemini = 'gemini';
    case Anthropic = 'anthropic';
    case Jev = 'jev';

    /**
     * 実行先の種類で絞った一覧（宣言順）。
     *
     * @return array<int, AiProvider>
     */
    public static function ofKind(AiKind $kind): array
    {
        return array_values(array_filter(self::cases(), fn (self $provider) => $provider->kind() === $kind));
    }

    public function label(): string
    {
        return match ($this) {
            self::Codex => 'codex（ChatGPTサブスク）',
            self::OpenAi => 'OpenAI API',
            self::Gemini => 'Gemini API',
            self::Anthropic => 'Anthropic API',
            self::Jev => 'Jev（TypeSafe AI）',
        };
    }

    /** 生成用か判定専用か。判定専用は Tier の実行先にも生成の Driver にも載せない */
    public function kind(): AiKind
    {
        return match ($this) {
            self::Codex, self::OpenAi, self::Gemini, self::Anthropic => AiKind::Generation,
            self::Jev => AiKind::Decision,
        };
    }

    /** サブプロセス実行か（API キーではなくローカルの CLI と認証で走る） */
    public function isSubprocess(): bool
    {
        return $this === self::Codex;
    }

    /**
     * API キーを書く .env の変数名。codex はサブプロセス実行でキーを持たないので null。
     * 画面はこの名前を「どこに書けばよいか」の案内に使う。
     */
    public function apiKeyEnvName(): ?string
    {
        return match ($this) {
            self::Codex => null,
            self::OpenAi => 'OPENAI_API_KEY',
            self::Gemini => 'GEMINI_API_KEY',
            self::Anthropic => 'ANTHROPIC_API_KEY',
            self::Jev => 'TYPESAFE_API_KEY',
        };
    }

    /**
     * モデル名の入力欄に出す例。単価表（config/ai.php の pricing）に載っているモデルを挙げ、
     * そのまま使えば推定費用が「単価未登録」にならないようにする。
     */
    public function modelPlaceholder(): string
    {
        return match ($this) {
            // codex は未指定なら CLI の既定モデルで走る
            self::Codex => '空欄なら既定モデル',
            self::OpenAi => '例: gpt-5.6-terra',
            self::Gemini => '例: gemini-3.8-flash',
            self::Anthropic => '例: claude-sonnet-5',
            self::Jev => '例: jev-1.13.0',
        };
    }

    /** API キーの発行やモデル名を確認する公式ドキュメント */
    public function docsUrl(): string
    {
        return match ($this) {
            self::Codex => 'https://github.com/openai/codex',
            self::OpenAi => 'https://platform.openai.com/docs/api-reference/responses',
            self::Gemini => 'https://ai.google.dev/gemini-api/docs',
            self::Anthropic => 'https://docs.claude.com/en/api/overview',
            self::Jev => 'https://docs.typesafe.ai/',
        };
    }
}
