<?php

namespace App\Services\Ai;

use App\Enums\AiProvider;

/**
 * プロバイダ固有オプション（ai_provider_configs.options の Tier ごとの鍵）の項目定義。
 *
 * 設定画面のフォーム項目（descriptor）と FormRequest の許容キーを同じ定義から出し、
 * プロバイダを足しても Vue を増やさず、画面に無い鍵が保存されないようにする。
 */
class ProviderOptionFields
{
    /** OpenAI reasoning.effort の選択肢（モデル依存。非対応モデルに送ると 400） */
    private const OPENAI_REASONING_EFFORTS = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];

    /**
     * Gemini 3 系の thinkingLevel。
     * 確認 2026-09-03: https://ai.google.dev/gemini-api/docs/generate-content/thinking
     */
    private const GEMINI_THINKING_LEVELS = ['minimal', 'low', 'medium', 'high'];

    /**
     * Gemini 2.5 系の thinkingBudget の範囲。-1 = 動的 / 0 = 思考オフ、上限はモデル別
     * （2.5 Pro が 128〜32768 で最大）。同上のページで確認。
     */
    private const GEMINI_THINKING_BUDGET_MIN = -1;

    private const GEMINI_THINKING_BUDGET_MAX = 32768;

    /**
     * Anthropic の拡張思考のモード。世代で対応が分かれる（公式のモデル別対応表より）:
     * - 4.5 以前（Opus 4.5 / Sonnet 4.5 / Haiku 4.5 等）: enabled + budget_tokens のみ。adaptive は 400
     * - 4.6（Opus 4.6 / Sonnet 4.6）: 両対応。enabled は deprecated だが動作する
     * - 4.7 以降（Opus 4.7 / 4.8 / Opus 5 / Sonnet 5 / Fable 5.1 / Mythos 5.1 等）: adaptive のみ。enabled は 400
     * effort の値は output_config.effort のもの。budget_tokens の下限は公式の 1,024。
     * 確認 2026-09-03: https://platform.claude.com/docs/en/build-with-claude/thinking-troubleshooting
     * （"Thinking support, defaults, and rejected configurations by model" の表）
     * ・ https://platform.claude.com/docs/en/build-with-claude/extended-thinking
     */
    private const ANTHROPIC_THINKING_MODES = ['adaptive', 'enabled'];

    private const ANTHROPIC_EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    private const ANTHROPIC_BUDGET_TOKENS_MIN = 1024;

    /**
     * 画面に出す項目定義。Tier（analysis / light）ごとに同じ項目を持つ。
     *
     * @return array<int, array{key: string, label: string, type: string, choices: array<int, array{value: string, label: string}>, min: int|null, max: int|null, step: int|null, help: string|null}>
     */
    public function fieldsFor(AiProvider $provider): array
    {
        return match ($provider) {
            AiProvider::OpenAi => [
                $this->select(
                    'reasoning_effort',
                    '推論の強さ（reasoning effort）',
                    self::OPENAI_REASONING_EFFORTS,
                    '未設定なら送らない（モデルの既定）。推論モデル以外に指定すると API が 400 を返す',
                ),
            ],
            AiProvider::Gemini => [
                $this->select(
                    'thinking_level',
                    '思考の深さ（thinking level・Gemini 3 系）',
                    self::GEMINI_THINKING_LEVELS,
                    '未設定なら送らない（モデルの既定）。2.5 系は非対応なので下の思考の予算を使う。両方は指定できない',
                ),
                $this->number(
                    'thinking_budget',
                    '思考の予算（thinking budget・Gemini 2.5 系）',
                    self::GEMINI_THINKING_BUDGET_MIN,
                    self::GEMINI_THINKING_BUDGET_MAX,
                    '未設定なら送らない（モデルの既定）。0 で思考オフ、-1 で動的。上限はモデル別（2.5 Pro は 128〜32768、2.5 Flash は 0〜24576）。3 系は非対応',
                ),
            ],
            AiProvider::Anthropic => [
                $this->select(
                    'thinking_mode',
                    '拡張思考のモード（thinking mode）',
                    self::ANTHROPIC_THINKING_MODES,
                    '未設定なら送らない（モデルの既定）。adaptive は 4.6 以降で深さを effort、enabled は 4.6 以前で思考量を budget_tokens で指定する（4.6 は両対応で enabled は非推奨）。世代を取り違えると API が 400 を返す',
                ),
                $this->select(
                    'effort',
                    '推論の強さ（effort・adaptive 用）',
                    self::ANTHROPIC_EFFORTS,
                    'モードが adaptive のときだけ指定できる（未設定なら API の既定 high）',
                ),
                $this->number(
                    'budget_tokens',
                    '思考の予算（budget tokens・enabled 用）',
                    self::ANTHROPIC_BUDGET_TOKENS_MIN,
                    $this->anthropicBudgetMax(),
                    'モードが enabled のときは必須。1024 以上かつ max_tokens（config/ai.php）未満。思考トークンも max_tokens に含まれる',
                ),
            ],
            AiProvider::Codex, AiProvider::Jev => [],
        };
    }

    /**
     * options で受け付ける鍵（画面に出している項目の鍵）。これ以外は保存しない。
     *
     * @return array<int, string>
     */
    public function keysFor(AiProvider $provider): array
    {
        return array_column($this->fieldsFor($provider), 'key');
    }

    /**
     * データの送り先について画面に出す注記（各社のデータ利用ポリシー）。確認日と URL は行ごとのコメントに残す。
     *
     * @return array<int, string>
     */
    public function notesFor(AiProvider $provider): array
    {
        return match ($provider) {
            // 既定の実行先であり、4 社の中で唯一「設定次第で学習に使われうる」ので画面にも出す
            AiProvider::Codex => [
                'ChatGPT のサブスク認証で走るため API 課金は発生しません。送信内容の学習への利用と保持は ChatGPT 側の設定に従います。',
            ],
            AiProvider::OpenAi => [
                'API で送信したデータは学習に使われません。不正利用監視のログとして 30 日保持されます。レスポンスは store:false で OpenAI 側に保存させません（送信データの保持は OpenAI の API データポリシーに従います）。',
            ],
            AiProvider::Gemini => [
                '無料枠のキーは送信内容が製品改善（学習）に使われ、人間のレビューも入ります。口コミ本文・面接メモを送るため、課金を有効にしたプロジェクトのキーを使ってください。',
                // 転記 2026-09-03: https://ai.google.dev/gemini-api/docs/pricing
                // 単価表（config/ai.php の pricing.gemini）に無いモデルを設定すると、費用は 0 ではなく「単価未登録」と表示される
                'Pro 系は入力が 200k トークンを超えると入力・出力とも単価が上がります（推定費用もその帯で計算します）。単価表に登録があるのは 3.1 Pro と Flash 系で、他のモデルを設定すると推定費用は「単価未登録」になります。',
            ],
            AiProvider::Anthropic => [
                'API で送信したデータは学習に使われません。Fable 5 / Mythos 5 系のモデルは 30 日間保持されます（ゼロデータ保持の対象外）。',
                // 確認 2026-09-03: https://platform.claude.com/docs/en/build-with-claude/structured-outputs
                '構造化出力のスキーマは本文とは別に最終利用から 24 時間キャッシュされ、本文と同じ保持の扱いを受けません。項目名・enum の値に個人情報を入れないでください。',
            ],
            // 転記 2026-09-22（公開情報をもとにした記載。公式の利用規約は未確認）
            AiProvider::Jev => [
                'TypeSafe AI（米国）の API に送信します。同社は API の入力を学習に使わないとしています。早期アクセスのため稼働保証はありません。',
                // 職務経歴・面接メモを送る判定機能を足すときは、その機能名と有効にする場所をここに書き足す
                'codex と違い、ローカルに送信内容の履歴を残しません。判定に必要な材料だけを送り、職務経歴・選考メモは送りません。面接メモは下の「面接メモを判定エンジンに送る」をオンにしたときだけ送ります。',
            ],
        };
    }

    /**
     * budget_tokens 欄の上限。思考トークンも max_tokens に含まれるため、予算は必ず max_tokens 未満
     * （= 上限は max_tokens - 1）。
     *
     * 設定が壊れている（ANTHROPIC_MAX_TOKENS が空・0 等）ときは例外にせず null を返し、
     * 入力欄の上限だけを外す。ここで落とすと BuildAiStatusAction が全プロバイダを回すため、
     * Anthropic を使わない利用者の /settings まで 500 になる。壊れた設定は
     * AnthropicDriver::maxTokens() が実行時に例外で止める（黙って別の値では走らせない）。
     */
    private function anthropicBudgetMax(): ?int
    {
        $maxTokens = config('ai.providers.anthropic.max_tokens');

        if (! is_numeric($maxTokens) || (int) $maxTokens <= self::ANTHROPIC_BUDGET_TOKENS_MIN) {
            return null;
        }

        return (int) $maxTokens - 1;
    }

    /**
     * @param  array<int, string>  $values
     * @return array{key: string, label: string, type: string, choices: array<int, array{value: string, label: string}>, min: int|null, max: int|null, step: int|null, help: string|null}
     */
    private function select(string $key, string $label, array $values, string $help): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'type' => 'select',
            'choices' => array_map(fn (string $v) => ['value' => $v, 'label' => $v], $values),
            'min' => null,
            'max' => null,
            'step' => null,
            'help' => $help,
        ];
    }

    /**
     * @return array{key: string, label: string, type: string, choices: array<int, array{value: string, label: string}>, min: int|null, max: int|null, step: int|null, help: string|null}
     */
    private function number(string $key, string $label, ?int $min, ?int $max, string $help): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'type' => 'number',
            'choices' => [],
            'min' => $min,
            'max' => $max,
            'step' => 1,
            'help' => $help,
        ];
    }
}
