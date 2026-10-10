<?php

namespace Tests\Unit;

use App\Services\SourceTextCleaner;
use PHPUnit\Framework\TestCase;

class SourceTextCleanerTest extends TestCase
{
    private SourceTextCleaner $cleaner;

    protected function setUp(): void
    {
        $this->cleaner = new SourceTextCleaner;
    }

    public function test_定型行を落として本文を残す(): void
    {
        $raw = <<<'TEXT'
            メニュー
            ログイン
            新規会員登録

            【募集要項】
            想定年収: 500万円〜800万円（経験・スキルを考慮）
            勤務地: 東京都渋谷区

            この求人に応募する
            気になるリストに追加
            おすすめの求人
            Copyright 2026 Example Inc.
            TEXT;

        $result = $this->cleaner->clean($raw);

        $this->assertStringContainsString('想定年収: 500万円〜800万円', $result['text']);
        $this->assertStringContainsString('勤務地: 東京都渋谷区', $result['text']);
        $this->assertStringNotContainsString('ログイン', $result['text']);
        $this->assertStringNotContainsString('この求人に応募する', $result['text']);
        $this->assertStringNotContainsString('Copyright', $result['text']);
        $this->assertGreaterThan(0, $result['removedLines']);
    }

    public function test_長い本文行はキーワードを含んでも落とさない(): void
    {
        $raw = '当社は個人情報の取扱について社内規程を整備し、全社員に年1回の研修を実施しています。';

        $result = $this->cleaner->clean($raw);

        $this->assertSame($raw, $result['text'], '本文中の言及は残す');
        $this->assertSame(0, $result['removedLines']);
    }

    public function test_連続する重複行をまとめる(): void
    {
        $raw = "採用情報\n採用情報\n採用情報\n年間休日125日";

        $result = $this->cleaner->clean($raw);

        $this->assertSame(1, substr_count($result['text'], '採用情報'));
        $this->assertStringContainsString('年間休日125日', $result['text']);
    }

    public function test_空行が3つ以上続いたら圧縮する(): void
    {
        $result = $this->cleaner->clean("前半\n\n\n\n\n後半");

        $this->assertSame("前半\n\n後半", $result['text']);
    }

    public function test_求人サイト共通の導線語とカタカナ表記のノイズを落とす(): void
    {
        // 実データ（マイナビ系求人票）でクリーニング後も残っていた行
        $raw = "求人を探す\nオススメの求人\n転職フェア\n簡単1分\n【募集要項】社内SEを募集します";

        $result = $this->cleaner->clean($raw);

        $this->assertSame('【募集要項】社内SEを募集します', $result['text']);
        $this->assertSame(4, $result['removedLines']);
    }
}
