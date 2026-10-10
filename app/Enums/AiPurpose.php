<?php

namespace App\Enums;

/**
 * AI を呼ぶ用途。何に実行枠・費用を使ったかを後から分解できるようにする。
 * 値は ai_usages.purpose に保存するため、既存値の文字列は変えない。
 */
enum AiPurpose: string
{
    case Analysis = 'analysis';
    case Questions = 'questions';
    case Briefing = 'briefing';
    case ClipClassify = 'clip_classify';
    case ClipList = 'clip_list';
    case ReviewSplit = 'review_split';
    case ConditionExtract = 'condition_extract';
    case MailExtract = 'mail_extract';
    case IssueMatch = 'issue_match';
    case ConnectionTest = 'connection_test';
    case ReviewJudgment = 'review_judgment';
    case NewsScreen = 'news_screen';

    public function label(): string
    {
        return match ($this) {
            self::Analysis => '分析（P1〜P5）',
            self::Questions => '逆質問の生成',
            self::Briefing => '面接前ブリーフィング',
            self::ClipClassify => 'クリップの企業・種別判定',
            self::ClipList => '企業一覧の一括抽出',
            self::ReviewSplit => '口コミの分割',
            self::ConditionExtract => '労働条件の抽出',
            self::MailExtract => 'メールの企業・日程抽出',
            self::IssueMatch => '論点の消し込み判定',
            self::ConnectionTest => '接続テスト',
            self::ReviewJudgment => '口コミの機械判定',
            self::NewsScreen => 'ニュースの機械判定',
        };
    }

    /**
     * ユーザーがボタンを押して明示的に走らせるものか。
     * false のものはソース保存などに付随して自動で走るため、
     * 「気づかないうちに増える消費」として画面で区別する。
     */
    public function isExplicit(): bool
    {
        return match ($this) {
            // 口コミの機械判定は分析ボタンに付随して走る（分析の一部として明示実行に数える）
            self::Analysis, self::Questions, self::Briefing, self::ClipClassify, self::ClipList, self::ConnectionTest,
            // ニュースの機械判定はニュース画面のボタンで走らせる
            self::ReviewJudgment, self::NewsScreen => true,
            // メール抽出は定期取得に付随して自動で走るため、明示実行としては扱わない
            // 論点の消し込みは面接メモの保存に付随して走るため、明示実行としては扱わない
            self::ReviewSplit, self::ConditionExtract, self::MailExtract, self::IssueMatch => false,
        };
    }
}
