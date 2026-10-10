<?php

namespace App\Services\Ai;

use App\Enums\Recommendation;

/**
 * P5 の充足判定と P2/P3 の分析結果から、おすすめ度（Recommendation）を機械的に決定する。
 * 点数化はせず、確定計算は PHP 側で行う（codex には判定させない）方針に沿う。
 * 判定と同時に「なぜその判定になったか」の理由も返し、画面に表示する。
 */
class RecommendationEvaluator
{
    /**
     * @param  array<string, mixed>  $p5
     * @param  array<string, mixed>  $p2
     * @param  array<string, mixed>  $p3
     */
    public function evaluate(array $p5, array $p2, array $p3, bool $jobPostingVerified = true): Recommendation
    {
        return $this->decide($p5, $p2, $p3, $jobPostingVerified)['recommendation'];
    }

    /**
     * 判定理由（画面表示用の日本語1文）。
     *
     * @param  array<string, mixed>  $p5
     * @param  array<string, mixed>  $p2
     * @param  array<string, mixed>  $p3
     */
    public function reasonFor(array $p5, array $p2, array $p3, bool $jobPostingVerified = true): string
    {
        return $this->decide($p5, $p2, $p3, $jobPostingVerified)['reason'];
    }

    /**
     * @param  array<string, mixed>  $p5
     * @param  array<string, mixed>  $p2
     * @param  array<string, mixed>  $p3
     * @return array{recommendation: Recommendation, reason: string}
     */
    private function decide(array $p5, array $p2, array $p3, bool $jobPostingVerified): array
    {
        $decision = $this->baseDecision($p5, $p2, $p3, $jobPostingVerified);

        // 危険サイン（P3 のレトリック検出・固定残業の記載）は判定条件には入れない。
        // ほぼ全ての求人票で注意表現が1件以上出るため、条件に入れると◎が事実上出なくなる。
        // ただし理由文には必ず併記し、「矛盾なし」を「安全」と読み違えられないようにする。
        $note = $this->rhetoricNote($p3, $jobPostingVerified);
        if ($note !== null) {
            $decision['reason'] .= ' '.$note;
        }

        return $decision;
    }

    /**
     * 危険サインの併記文（言及すべきものが無ければ null）。
     * 判定には使わず、理由文の末尾に足すだけ。
     *
     * @param  array<string, mixed>  $p3
     */
    private function rhetoricNote(array $p3, bool $jobPostingVerified): ?string
    {
        // 求人票が無ければ P3 自体が動かない。保留理由と重複するので触れない
        if (! $jobPostingVerified) {
            return null;
        }

        // P3 の出力が無い（未実施・旧形式）ランで「危険サインなし」と読ませない
        if (! array_key_exists('rhetoric_flags', $p3)) {
            return '※求人票の危険サイン（注意表現・固定残業の記載）は未判定です。';
        }

        $parts = [];
        $flagCount = is_array($p3['rhetoric_flags']) ? count($p3['rhetoric_flags']) : 0;
        if ($flagCount >= 1) {
            $parts[] = "注意表現{$flagCount}件";
        }

        // 固定残業の有無は P3 の salary_breakdown.has_fixed_overtime（真偽値）で分かる。
        // 真偽値以外・キー無しは「無し」に丸めず未判定と書く
        $breakdown = $p3['salary_breakdown'] ?? null;
        $hasFixedOvertime = is_array($breakdown) ? ($breakdown['has_fixed_overtime'] ?? null) : null;
        if (! is_bool($hasFixedOvertime)) {
            $parts[] = '固定残業の記載は未判定';
        } elseif ($hasFixedOvertime) {
            $parts[] = '固定残業の記載あり';
        }

        return $parts === [] ? null : '※'.implode('・', $parts).'（危険サイン参照）';
    }

    /**
     * 危険サインの併記を除いた素の判定。
     *
     * @param  array<string, mixed>  $p5
     * @param  array<string, mixed>  $p2
     * @param  array<string, mixed>  $p3
     * @return array{recommendation: Recommendation, reason: string}
     */
    private function baseDecision(array $p5, array $p2, array $p3, bool $jobPostingVerified): array
    {
        // (z) 求人票を1件も検証していない（P1がスキップ）場合は、材料不足として判定を保留する。
        //     口コミだけで「◎ 前向き検討」を出すと誤誘導になるため。
        if (! $jobPostingVerified) {
            return [
                'recommendation' => Recommendation::Hold,
                'reason' => '求人票が未投入のため判定を保留しました（口コミだけでは条件の充足を確認できません）。',
            ];
        }

        // (a0) NG条件に該当（hit）が1つでもあれば Low。
        //      hit の確定は codex の作文ではなくこの PHP 分岐で行う。unknown は保留扱いで判定に影響させない。
        $ngHits = $this->ngHitConditions($p5);
        if ($ngHits !== []) {
            return [
                'recommendation' => Recommendation::Low,
                'reason' => 'NG条件『'.implode('』『', $ngHits).'』に該当の可能性があります。',
            ];
        }

        // (a) 必須条件に不充足（unmet）が1つでもあれば Low。
        $unmet = $this->conditionsWithStatus($p5, 'unmet');
        if ($unmet !== []) {
            return [
                'recommendation' => Recommendation::Low,
                'reason' => '必須条件に不充足が'.count($unmet).'件あります（'.implode(' / ', $unmet).'）。',
            ];
        }

        // (b) 未確認（unknown）の必須条件・矛盾・具体性の低さがあれば Mid。
        //     材料が無くて確認できていない条件を「満たした」とは扱わない（◎を安売りしない）。
        $unknown = $this->conditionsWithStatus($p5, 'unknown');
        $contradictCount = $this->countVerdict($p2, 'contradict');
        // 複数人で裏取りしたとき、判定者の意見が分かれた主張。材料不足（unverifiable）と違い
        // 材料を足しても解消しないため、確認が必要な要因として矛盾と並べて扱う
        $splitCount = $this->countVerdict($p2, VerdictConsensus::SPLIT);
        $concretenessLow = (($p3['concreteness'] ?? [])['level'] ?? null) === 'low';

        if ($unknown !== [] || $contradictCount >= 1 || $splitCount >= 1 || $concretenessLow) {
            $factors = [];
            if ($unknown !== []) {
                $factors[] = '必須条件のうち'.count($unknown).'件が未確認（'.implode(' / ', $unknown).'）';
            }
            if ($contradictCount >= 1) {
                $factors[] = "主張と材料の矛盾が{$contradictCount}件";
            }
            if ($splitCount >= 1) {
                $factors[] = "判定者の意見が分かれた主張が{$splitCount}件";
            }
            if ($concretenessLow) {
                $factors[] = '求人票の具体性が低い';
            }

            return [
                'recommendation' => Recommendation::Mid,
                'reason' => implode('・', $factors).'のため、確認が必要です。',
            ];
        }

        // (c) それ以外は High。
        return [
            'recommendation' => Recommendation::High,
            'reason' => '必須条件を満たし、主張と材料の矛盾も見つかりませんでした。',
        ];
    }

    /**
     * NG条件のうち該当（hit）と判定された条件名を返す。
     *
     * @param  array<string, mixed>  $p5
     * @return array<int, string>
     */
    private function ngHitConditions(array $p5): array
    {
        $hits = [];
        // ng_checks 導入(2026-07)以前のランには存在しないため、キーが無ければ空配列として扱う
        foreach ($p5['ng_checks'] ?? [] as $check) {
            if (($check['status'] ?? null) === 'hit') {
                $hits[] = (string) ($check['condition'] ?? '条件不明');
            }
        }

        return $hits;
    }

    /**
     * 指定ステータスの必須条件名を返す。
     *
     * @param  array<string, mixed>  $p5
     * @return array<int, string>
     */
    private function conditionsWithStatus(array $p5, string $status): array
    {
        $matched = [];
        foreach ($p5['must_checks'] ?? [] as $check) {
            if (($check['status'] ?? null) === $status) {
                $matched[] = (string) ($check['condition'] ?? '条件不明');
            }
        }

        return $matched;
    }

    /**
     * @param  array<string, mixed>  $p2
     */
    private function countVerdict(array $p2, string $target): int
    {
        $count = 0;
        foreach ($p2['verdicts'] ?? [] as $verdict) {
            if (($verdict['verdict'] ?? null) === $target) {
                $count++;
            }
        }

        return $count;
    }
}
