<?php

namespace Tests\Unit;

use App\Models\MailSetting;
use App\Services\Mail\MailMasker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * codex に渡す前の個人情報マスク。
 * 「渡してはいけないものが渡らない」ことの回帰防止なので、抜けと過剰の両方を見る。
 */
class MailMaskerTest extends TestCase
{
    use RefreshDatabase;

    private function masker(): MailMasker
    {
        return app(MailMasker::class);
    }

    public function test_メールアドレスが伏せられる(): void
    {
        $masked = $this->masker()->mask('ご連絡は taro.yamada+job@example.co.jp までお願いします');

        $this->assertStringNotContainsString('taro.yamada+job@example.co.jp', $masked);
        $this->assertStringContainsString('[メールアドレス]', $masked);
    }

    public function test_電話番号が伏せられる(): void
    {
        $masked = $this->masker()->mask("携帯 090-1234-5678\n固定 03-1234-5678");

        $this->assertStringNotContainsString('090-1234-5678', $masked);
        $this->assertStringNotContainsString('03-1234-5678', $masked);
        $this->assertSame(2, substr_count($masked, '[電話番号]'));
    }

    public function test_設定した氏名が伏せられる(): void
    {
        MailSetting::create(['mask_words' => '高橋花子, 高橋']);

        $masked = $this->masker()->mask('高橋花子 様、この度は…');

        $this->assertStringNotContainsString('高橋', $masked);
        $this->assertStringContainsString('[本人情報]', $masked);
    }

    public function test_担当者名は伏せられない(): void
    {
        MailSetting::create(['mask_words' => '']);

        $masked = $this->masker()->mask('採用担当の山田と申します。面接官は田中です。');

        // 企業側の人物は面接相手として有用なため残す
        $this->assertStringContainsString('山田', $masked);
        $this->assertStringContainsString('田中', $masked);
    }

    public function test_日付や金額を電話番号と誤認しない(): void
    {
        MailSetting::create(['mask_words' => '']);

        $masked = $this->masker()->mask('面接は2026-09-01です。年収は4,000,000円〜6,000,000円。');

        $this->assertStringContainsString('2026-09-01', $masked);
        $this->assertStringContainsString('4,000,000', $masked);
        $this->assertStringNotContainsString('[電話番号]', $masked);
    }

    public function test_マスク対象が本文に無くても例外にならない(): void
    {
        MailSetting::create(['mask_words' => '']);

        $this->assertSame('スカウトのご案内です', $this->masker()->mask('スカウトのご案内です'));
    }

    public function test_空文字でも落ちない(): void
    {
        $this->assertSame('', $this->masker()->mask(''));
    }

    public function test_全角の電話番号も伏せられる(): void
    {
        MailSetting::create(['mask_words' => '']);

        $masked = $this->masker()->mask('お電話は０９０－１２３４－５６７８まで');

        $this->assertStringNotContainsString('１２３４', $masked);
        $this->assertStringContainsString('[電話番号]', $masked);
    }

    public function test_括弧区切りの電話番号も伏せられる(): void
    {
        MailSetting::create(['mask_words' => '']);

        $masked = $this->masker()->mask('代表 03(1234)5678');

        $this->assertStringNotContainsString('1234', $masked);
        $this->assertStringContainsString('[電話番号]', $masked);
    }

    public function test_全角のメールアドレスも伏せられる(): void
    {
        MailSetting::create(['mask_words' => '']);

        $masked = $this->masker()->mask('ご返信は ｉｎｆｏ＠ｅｘａｍｐｌｅ．ｃｏｍ へ');

        $this->assertStringContainsString('[メールアドレス]', $masked);
    }
}
