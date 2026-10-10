<?php

namespace App\Services\Edinet;

/**
 * 有価証券報告書のXBRL-CSV（タブ区切り）から従業員関連の数値を抜き出す。
 * 対象は提出会社の 平均年間給与 / 平均年齢 / 平均勤続年数 / 従業員数。
 * 数値変換（円→万円）・年度の確定はPHPで行う。
 *
 * 有報CSVは当期（CurrentYear）だけでなく前期以前（Prior1Year…）の行も含むため、
 * 年度オフセットごとに値を分けて返す（推移を残すため）。
 * ただし年度軸を広げる分、連結行・セグメント行を取り違えないよう
 * コンテキストのメンバー（連結/個別/セグメント）で従来どおり絞り込む。
 */
class EdinetYuhoParser
{
    /** 要素ID（jpcrp_cor: プレフィックスは除いた形で照合） */
    private const ELEMENT_MAP = [
        'AverageAnnualSalaryInformationAboutReportingCompanyInformationAboutEmployees' => 'salary',
        'AverageAgeYearsInformationAboutReportingCompanyInformationAboutEmployees' => 'age',
        'AverageLengthOfServiceYearsInformationAboutReportingCompanyInformationAboutEmployees' => 'tenure',
    ];

    /**
     * 提出会社（個別）の値を指すメンバー。
     * 平均年間給与等は要素IDそのものが提出会社向けのため、メンバー無し（'')でも提出会社の値。
     * セグメント・連結メンバーが付いた行は別スコープなので取らない。
     */
    private const REPORTING_COMPANY_MEMBERS = ['', 'NonConsolidatedMember'];

    /** 従業員数は連結・セグメント行もあるため、提出会社（個別）のメンバーに限定する */
    private const NON_CONSOLIDATED_MEMBER = 'NonConsolidatedMember';

    /**
     * @param  string  $fiscalYear  書類の決算期の年（当期 = CurrentYear の年度）
     * @return array<int, array{key: string, value: string, unit: string, fiscal_year: string}>
     */
    public function metrics(string $csvText, string $fiscalYear): array
    {
        if (preg_match('/^\d{4}$/', $fiscalYear) !== 1) {
            throw new \InvalidArgumentException("年度は4桁の西暦で渡してください: {$fiscalYear}");
        }

        /** @var array<int, array<string, string>> $found 年度オフセット => 種別 => 値 */
        $found = [];

        foreach (explode("\n", $csvText) as $line) {
            $cols = str_getcsv(rtrim($line, "\r"), "\t", '"', '');
            if (count($cols) < 9) {
                continue;
            }

            [$elementId, , $contextId] = $cols;
            $value = trim((string) end($cols));
            if ($value === '') {
                continue;
            }

            $context = $this->context((string) $contextId);
            if ($context === null) {
                continue;
            }
            [$offset, $member] = $context;

            foreach (self::ELEMENT_MAP as $suffix => $kind) {
                if (str_ends_with((string) $elementId, $suffix)
                    && in_array($member, self::REPORTING_COMPANY_MEMBERS, true)) {
                    $found[$offset][$kind] = $value;
                }
            }

            if (str_ends_with((string) $elementId, ':NumberOfEmployees')
                && $member === self::NON_CONSOLIDATED_MEMBER) {
                $found[$offset]['employees'] = $value;
            }
        }

        // 当期（オフセット0）から古い年度へ並べる
        ksort($found);

        $metrics = [];
        foreach ($found as $offset => $values) {
            $metrics = array_merge($metrics, $this->format($values, (string) (((int) $fiscalYear) - $offset)));
        }

        return $metrics;
    }

    /**
     * コンテキストIDを「年度オフセット」と「メンバー」に分解する。
     * 例: CurrentYearInstant → [0, '']、Prior1YearInstant_NonConsolidatedMember → [1, 'NonConsolidatedMember']。
     * 有報の年度軸（YearInstant/YearDuration）以外（四半期のYTD等）は対象外として null を返す。
     *
     * @return array{0: int, 1: string}|null
     */
    private function context(string $contextId): ?array
    {
        if (preg_match('/^(?:CurrentYear|Prior(\d+)Year)(?:Instant|Duration)(?:_(.+))?$/', $contextId, $m) !== 1) {
            return null;
        }

        return [(int) ($m[1] ?? 0), $m[2] ?? ''];
    }

    /**
     * @param  array<string, string>  $found
     * @return array<int, array{key: string, value: string, unit: string, fiscal_year: string}>
     */
    private function format(array $found, string $fiscalYear): array
    {
        $metrics = [];

        if (isset($found['salary']) && is_numeric($found['salary'])) {
            $metrics[] = ['key' => '平均年間給与', 'value' => (string) round(((float) $found['salary']) / 10000), 'unit' => '万円'];
        }
        if (isset($found['age'])) {
            $metrics[] = ['key' => '平均年齢', 'value' => $found['age'], 'unit' => '歳'];
        }
        if (isset($found['tenure'])) {
            $metrics[] = ['key' => '平均勤続年数', 'value' => $found['tenure'], 'unit' => '年'];
        }
        if (isset($found['employees'])) {
            $metrics[] = ['key' => '従業員数(単体)', 'value' => $found['employees'], 'unit' => '人'];
        }

        return array_map(fn (array $m): array => $m + ['fiscal_year' => $fiscalYear], $metrics);
    }
}
