<?php

namespace App\Enums;

enum AnalysisStep: string
{
    case P1 = 'p1';
    // 口コミの機械判定（判定 API。プロンプトと JSON スキーマを持たない）
    case RJ = 'rj';
    case P2 = 'p2';
    case P3 = 'p3';
    case P4 = 'p4';
    case P5 = 'p5';
    case P6 = 'p6';

    public function label(): string
    {
        return match ($this) {
            self::P1 => '主張抽出',
            self::RJ => '口コミの機械判定',
            self::P2 => '裏取り判定',
            self::P3 => 'レトリック検出',
            self::P4 => '口コミ品質処理',
            self::P5 => '適合評価',
            self::P6 => '逆質問生成',
        };
    }

    /**
     * 分析実行（AnalyzeCompanyJob）で自動実行するステップの順。
     *
     * P6（逆質問）は含めない。面接に進まない企業でも毎回 codex を消費してしまうため、
     * 面接が決まってから「逆質問を生成」ボタンで個別に実行する（GenerateQuestionsJob）。
     * 出力先は同じ analysis_steps（step=p6）で、表示・エクスポート経路は分けていない。
     * RJ は P1 の主張と口コミを突き合わせるので P1 の直後、その結果を材料選抜に使う P2 の前に置く。
     *
     * @return array<int, self>
     */
    public static function pipeline(): array
    {
        return [self::P1, self::RJ, self::P2, self::P3, self::P4, self::P5];
    }

    /**
     * カルテを構成する全ステップ（自動実行しない P6 を含む）。
     * 画面表示・Markdown書き出し・ブリーフィングの材料収集はこちらを使う。
     *
     * @return array<int, self>
     */
    public static function all(): array
    {
        return [...self::pipeline(), self::P6];
    }

    /**
     * カルテ・ブリーフィングに出力を載せるステップ（all() から RJ を除く）。
     * RJ の出力は口コミごとの確率なので画面・生成 AI に渡さず、ClaimReviewAggregate が件数にして出す。
     *
     * @return array<int, self>
     */
    public static function karte(): array
    {
        return array_values(array_filter(self::all(), fn (self $step) => $step !== self::RJ));
    }

    /** プロンプトファイル名（resources/prompts/p1.txt 等） */
    public function promptFile(): string
    {
        if ($this === self::RJ) {
            throw new \LogicException('rj はプロンプトを持たない（判定 API）');
        }

        return $this->value.'.txt';
    }
}
