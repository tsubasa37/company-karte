<?php

namespace Tests\Unit;

use App\Services\Edinet\EdinetDocumentUrl;
use App\Services\Edinet\EdinetYuhoParser;
use PHPUnit\Framework\TestCase;

class EdinetYuhoParserTest extends TestCase
{
    /**
     * @param  array<int, string>  $rows
     */
    private function csv(array $rows): string
    {
        return implode("\n", array_merge(
            ["要素ID\t項目名\tコンテキストID\t相対年度\t連結・個別\t期間・時点\tユニットID\t単位\t値"],
            $rows,
        ));
    }

    private function row(string $element, string $context, string $value): string
    {
        return implode("\t", ['jpcrp_cor:'.$element, '項目', $context, '当期', '個別', '時点', 'pure', '単位', $value]);
    }

    public function test_当期と前期の両方を年度別に返す(): void
    {
        $csv = $this->csv([
            $this->row('AverageAnnualSalaryInformationAboutReportingCompanyInformationAboutEmployees', 'CurrentYearInstant', '6297000'),
            $this->row('AverageAnnualSalaryInformationAboutReportingCompanyInformationAboutEmployees', 'Prior1YearInstant', '6100000'),
            $this->row('NumberOfEmployees', 'CurrentYearInstant_NonConsolidatedMember', '524'),
            $this->row('NumberOfEmployees', 'Prior1YearInstant_NonConsolidatedMember', '500'),
        ]);

        $metrics = (new EdinetYuhoParser)->metrics($csv, '2026');

        $this->assertSame(
            [
                ['key' => '平均年間給与', 'value' => '630', 'unit' => '万円', 'fiscal_year' => '2026'],
                ['key' => '従業員数(単体)', 'value' => '524', 'unit' => '人', 'fiscal_year' => '2026'],
                ['key' => '平均年間給与', 'value' => '610', 'unit' => '万円', 'fiscal_year' => '2025'],
                ['key' => '従業員数(単体)', 'value' => '500', 'unit' => '人', 'fiscal_year' => '2025'],
            ],
            $metrics,
            '当期→過去の順に、年度ぶんだけ返す（円→万円はPHPで確定変換）',
        );
    }

    public function test_連結行とセグメント行は取らない(): void
    {
        $csv = $this->csv([
            // 連結（メンバー無し）の従業員数と、個別のうちセグメント別の行
            $this->row('NumberOfEmployees', 'CurrentYearInstant', '1200'),
            $this->row('NumberOfEmployees', 'CurrentYearInstant_NonConsolidatedMember_ReportableSegmentsMember', '80'),
            $this->row('NumberOfEmployees', 'CurrentYearInstant_NonConsolidatedMember', '524'),
            // 提出会社向けの要素でもセグメントメンバー付きは別スコープなので取らない
            $this->row('AverageAgeYearsInformationAboutReportingCompanyInformationAboutEmployees', 'CurrentYearInstant_SalesSegmentMember', '31.0'),
            $this->row('AverageAgeYearsInformationAboutReportingCompanyInformationAboutEmployees', 'CurrentYearInstant', '42.3'),
        ]);

        $metrics = (new EdinetYuhoParser)->metrics($csv, '2026');

        $this->assertSame([
            ['key' => '平均年齢', 'value' => '42.3', 'unit' => '歳', 'fiscal_year' => '2026'],
            ['key' => '従業員数(単体)', 'value' => '524', 'unit' => '人', 'fiscal_year' => '2026'],
        ], $metrics);
    }

    public function test_四半期などの年度軸でないコンテキストは無視する(): void
    {
        $csv = $this->csv([
            $this->row('AverageAgeYearsInformationAboutReportingCompanyInformationAboutEmployees', 'CurrentYTDDuration', '39.9'),
            $this->row('AverageAgeYearsInformationAboutReportingCompanyInformationAboutEmployees', 'CurrentQuarterInstant', '38.8'),
        ]);

        $this->assertSame([], (new EdinetYuhoParser)->metrics($csv, '2026'));
    }

    public function test_前期以前も年度を遡って付ける(): void
    {
        $csv = $this->csv([
            $this->row('AverageLengthOfServiceYearsInformationAboutReportingCompanyInformationAboutEmployees', 'Prior2YearDuration', '7.5'),
        ]);

        $this->assertSame([
            ['key' => '平均勤続年数', 'value' => '7.5', 'unit' => '年', 'fiscal_year' => '2024'],
        ], (new EdinetYuhoParser)->metrics($csv, '2026'));
    }

    public function test_年度が4桁の西暦でなければ例外(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new EdinetYuhoParser)->metrics($this->csv([]), '2026-03-31');
    }

    public function test_書類閲覧_ur_lを書類管理番号から組み立てる(): void
    {
        $this->assertSame(
            'https://disclosure2.edinet-fsa.go.jp/WZEK0040.aspx?S100AAAA=',
            EdinetDocumentUrl::viewer('S100AAAA'),
        );
    }

    public function test_書類管理番号の形式が不正なら例外(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EdinetDocumentUrl::viewer('S100AAAA&x=../evil');
    }
}
