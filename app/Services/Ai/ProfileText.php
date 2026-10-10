<?php

namespace App\Services\Ai;

use App\Models\Profile;

/**
 * プロンプトに差し込むプロファイル（職務経歴・条件・転職の軸）のテキスト表現。
 * 分析(AnalysisRunner)とブリーフィング生成で共通利用。
 */
class ProfileText
{
    public function build(): string
    {
        $p = Profile::first();
        if (! $p) {
            return '（プロファイル未設定）';
        }

        $parts = [];
        if ($p->career_summary) {
            $parts[] = "職務経歴: {$p->career_summary}";
        }
        if (! empty($p->must_json)) {
            $parts[] = '必須条件: '.implode(' / ', $p->must_json);
        }
        if (! empty($p->want_json)) {
            $parts[] = '歓迎条件: '.implode(' / ', $p->want_json);
        }
        if (! empty($p->ng_json)) {
            $parts[] = 'NG条件: '.implode(' / ', $p->ng_json);
        }
        if ($p->axis) {
            $parts[] = "転職の軸: {$p->axis}";
        }

        return $parts !== [] ? implode("\n", $parts) : '（プロファイル未設定）';
    }
}
