<?php

namespace App\Services;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

/**
 * 2つの本文の「変わった行」だけを返す（追加/削除）。AIは使わずPHPで確定計算する。
 */
class SourceDiffService
{
    /** 表示が壊れないよう変化行（lines）はこの数で打ち切る（総数は addTotal/delTotal で別途返す） */
    public const MAX_LINES = 200;

    /**
     * 変化行の一覧（先頭 MAX_LINES 件）と、打ち切り前の総数・省略件数を返す。
     * 打ち切りを黙って行わない: 画面は truncated/omitted で省略を明示する前提。
     *
     * @return array{lines: array<int, array{type: string, line: string}>, addTotal: int, delTotal: int, truncated: bool, omitted: int}
     */
    public function changedLines(string $old, string $new): array
    {
        $differ = new Differ(new UnifiedDiffOutputBuilder);

        $result = [];
        foreach ($differ->diffToArray($this->normalize($old), $this->normalize($new)) as [$line, $flag]) {
            if ($flag === Differ::ADDED) {
                $result[] = ['type' => 'add', 'line' => rtrim((string) $line, "\n")];
            } elseif ($flag === Differ::REMOVED) {
                $result[] = ['type' => 'del', 'line' => rtrim((string) $line, "\n")];
            }
        }

        $addTotal = count(array_filter($result, fn (array $l) => $l['type'] === 'add'));

        return [
            'lines' => array_slice($result, 0, self::MAX_LINES),
            'addTotal' => $addTotal,
            'delTotal' => count($result) - $addTotal,
            'truncated' => count($result) > self::MAX_LINES,
            'omitted' => max(count($result) - self::MAX_LINES, 0),
        ];
    }

    /**
     * 末尾改行の有無だけの差を「最終行の変更」と誤検知しないよう、必ず改行で終わらせる。
     */
    private function normalize(string $text): string
    {
        return str_ends_with($text, "\n") ? $text : $text."\n";
    }
}
