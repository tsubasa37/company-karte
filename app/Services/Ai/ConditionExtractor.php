<?php

namespace App\Services\Ai;

use App\Enums\AiPurpose;
use App\Enums\AiTier;

/**
 * 求人票テキストから労働条件（年収レンジ・リモート・フレックス・固定残業の時間数/金額等）を構造化抽出する。
 * 記載の抜き出しのみを行い、推測で埋めさせない（比較ビューの横並び用）。
 * 金額の計算・換算はさせない（基本給・実質時給の算出は SalaryBreakdownCalculator が PHP で行う）。
 */
class ConditionExtractor
{
    /** 複数求人票の合算で渡す上限文字数 */
    private const MAX_CHARS = 20000;

    public function __construct(private readonly AiRunner $ai) {}

    /**
     * @return array<string, mixed>
     */
    public function extract(string $jobPostingText, ?int $companyId = null): array
    {
        $body = UntrustedText::wrap(mb_substr($jobPostingText, 0, self::MAX_CHARS));
        $rule = UntrustedText::RULE;

        $prompt = <<<PROMPT
            以下は求人票のテキストです。記載されている労働条件を抽出してください。

            {$rule}

            - salary_min / salary_max: 想定年収の下限・上限（万円・整数）。年収表記が無い（月給のみ等）場合は null にして salary_text に原文を書く
            - salary_text: 給与の原文表記（例: 年収500万〜800万円 / 月給30万円〜）
            - remote: リモート可否の記載（例: フルリモート可 / 週2出社 / 記載なしは空文字）
            - flex: フレックス制度の記載（無ければ空文字）
            - overtime_pay: 残業代の扱いの原文表記（例: 固定残業代45時間分を含む / 全額支給 / 記載なしは空文字）
            - fixed_overtime_hours: 原文に書かれた固定残業（みなし残業）の時間数（月あたりの時間・整数。記載が無ければ null）
            - fixed_overtime_amount_yen: 原文に書かれた固定残業代の金額（円・整数。記載が無ければ null）
            - monthly_salary_min_yen: 月給（月額）の下限（円・整数。年収表記のみで月額の記載が無ければ null）
            - monthly_salary_max_yen: 月給（月額）の上限（円・整数。単一額なら下限と同じ値、月額の記載が無ければ null）
            - location: 勤務地（無ければ空文字）
            - holidays: 休日・休暇の記載（例: 年間休日125日・完全週休2日 / 無ければ空文字）
            - notes: 比較時に効く補足があれば1文（無ければ空文字）

            ルール: 記載の抜き出しのみ。書かれていないことを推測で埋めない。
            **計算はしない**（基本給・実質時給の算出、年収から月給への換算、按分・推定はプログラム側で行う）。
            数値の書き方: 「月給25万円〜35万円」→ monthly_salary_min_yen=250000 / monthly_salary_max_yen=350000。
            「固定残業手当45時間分（6万円）を含む」→ fixed_overtime_hours=45 / fixed_overtime_amount_yen=60000。

            # 求人票テキスト
            {$body}
            PROMPT;

        // 記載の抜き出しだけの機械的な作業なので抽出・判定 Tier で足りる
        /** @var array<string, mixed> */
        return $this->ai->runWithSchema(
            $this->schema(),
            $prompt,
            AiTier::Light,
            AiPurpose::ConditionExtract,
            null,
            $companyId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'salary_min', 'salary_max', 'salary_text', 'remote', 'flex', 'overtime_pay',
                'fixed_overtime_hours', 'fixed_overtime_amount_yen',
                'monthly_salary_min_yen', 'monthly_salary_max_yen',
                'location', 'holidays', 'notes',
            ],
            'properties' => [
                'salary_min' => ['type' => ['integer', 'null']],
                'salary_max' => ['type' => ['integer', 'null']],
                'salary_text' => ['type' => 'string'],
                'remote' => ['type' => 'string'],
                'flex' => ['type' => 'string'],
                // 残業代の原文表記（画面で原文を見せるため残す）
                'overtime_pay' => ['type' => 'string'],
                // 固定残業・月額の構造化値（記載が無ければ null。計算はさせない）
                'fixed_overtime_hours' => ['type' => ['integer', 'null']],
                'fixed_overtime_amount_yen' => ['type' => ['integer', 'null']],
                'monthly_salary_min_yen' => ['type' => ['integer', 'null']],
                'monthly_salary_max_yen' => ['type' => ['integer', 'null']],
                'location' => ['type' => 'string'],
                'holidays' => ['type' => 'string'],
                'notes' => ['type' => 'string'],
            ],
        ];
    }
}
