<?php

namespace App\Services\Ai;

use App\Enums\VerdictPerspective;

/**
 * 複数の判定者が独立に出した P2（裏取り）の判定を、PHP 側で1つに確定する。
 *
 * 確定ルールは「過半数の多数決。過半数が無ければ unverifiable（検証不能）」。
 * 判定が割れた状態は P2 プロンプトの unverifiable の定義（材料同士が拮抗している）と一致するため、
 * 多数派が作れないときは検証不能に倒す。判定の確定は AI にさせず、この PHP 側で行う。
 *
 * 判定文（rationale）は書き換えない。確定した判定と各判定者の意見を consensus に添えるだけで、
 * 合議の結果を説明する文章を新しく作文することはしない。
 */
class VerdictConsensus
{
    /** 判定者が出せる判定の種別（スキーマ外の値は票として数えない） */
    private const VERDICTS = ['match', 'contradict', 'unverifiable'];

    /**
     * 判定者の意見が実際に分かれたときに PHP が付ける確定値。
     * AI には出させない（AI の語彙は上の3値のまま）。
     *
     * 「材料が無い（unverifiable）」と混ぜないために独立した値にしている。
     * 材料不足は材料を足せば解消するが、解釈の相違は材料を足しても解消せず、
     * 面接で事実を確認するしかない。次に取る行動が正反対なので、同じ値にしてはいけない。
     */
    public const SPLIT = 'split';

    /** 判定した人数が足りず過半数に届かなかったときの確定値（材料不足と同じ扱い） */
    private const INSUFFICIENT = 'unverifiable';

    /**
     * @param  array<int, array{perspective: VerdictPerspective, output: array<string, mixed>}>  $opinions
     *                                                                                                      判定者ごとの出力（パネル順。先頭が1人目 = Base）
     * @return array<string, mixed> 集計後の P2 出力（形は単独実行時と同じ {verdicts: [...]}）
     */
    public function merge(array $opinions): array
    {
        if ($opinions === []) {
            throw new \InvalidArgumentException('集計する判定が1件もありません');
        }

        $panelSize = count($opinions);
        [$entries, $primary] = $this->index($opinions);

        $verdicts = [];
        foreach ($entries as $entry) {
            $verdicts[] = $entry['type'] === 'aggregate'
                ? $this->aggregate($entry['claim_index'], $primary, $opinions, $panelSize)
                : $this->passThrough($entry['row'], $opinions[$entry['panelist']], $panelSize);
        }

        return ['verdicts' => $verdicts];
    }

    /**
     * 判定行を claim_index で引けるようにしつつ、出力順を決める。
     *
     * 出力順は「1人目の行の順 → 2人目以降で新しく出てきた行」。人数1のときは
     * 判定者の出力順がそのまま残り、多重化前と行の並びが変わらない。
     *
     * claim_index がどの主張も指していない行（null・非整数）と、同じ判定者が同じ主張に
     * 2件目以降を出した行は合議できない。捨てると指摘が消えるため、そのまま併記する行として残す。
     *
     * @param  array<int, array{perspective: VerdictPerspective, output: array<string, mixed>}>  $opinions
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<int, array<string, mixed>>>}
     */
    private function index(array $opinions): array
    {
        $entries = [];
        $primary = [];
        $seenIndex = [];

        foreach ($opinions as $panelist => $opinion) {
            $seenByThisPanelist = [];

            foreach ($opinion['output']['verdicts'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $claimIndex = $row['claim_index'] ?? null;

                if (! is_int($claimIndex) || isset($seenByThisPanelist[$claimIndex])) {
                    $entries[] = ['type' => 'extra', 'panelist' => $panelist, 'row' => $row];

                    continue;
                }

                $seenByThisPanelist[$claimIndex] = true;
                $primary[$panelist][$claimIndex] = $row;

                if (! isset($seenIndex[$claimIndex])) {
                    $seenIndex[$claimIndex] = true;
                    $entries[] = ['type' => 'aggregate', 'claim_index' => $claimIndex];
                }
            }
        }

        return [$entries, $primary];
    }

    /**
     * 1つの主張について、判定者ごとの票を数えて判定を確定する。
     *
     * @param  array<int, array<int, array<string, mixed>>>  $primary
     * @param  array<int, array{perspective: VerdictPerspective, output: array<string, mixed>}>  $opinions
     * @return array<string, mixed>
     */
    private function aggregate(int $claimIndex, array $primary, array $opinions, int $panelSize): array
    {
        $counts = array_fill_keys(self::VERDICTS, 0);
        $votes = [];
        $rows = [];
        $panelOpinions = [];

        foreach ($opinions as $panelist => $opinion) {
            $row = $primary[$panelist][$claimIndex] ?? null;
            if ($row === null) {
                continue;
            }

            $verdict = $row['verdict'] ?? null;
            $rows[$panelist] = $row;
            $votes[$panelist] = $verdict;

            if (is_string($verdict) && isset($counts[$verdict])) {
                $counts[$verdict]++;
            }

            $panelOpinions[] = $this->opinionOf($opinion['perspective'], $row);
        }

        // 最多票がただ1つで、かつ過半数（票数×2 > 人数）に達しているときだけその判定で確定。
        $max = max($counts);
        $tops = array_keys($counts, $max, true);
        $hasMajority = count($tops) === 1 && $max * 2 > $panelSize;
        // 実際に何種類の判定が出たか（1種類なら意見は割れていない）
        $distinct = count(array_filter($counts));

        $decided = match (true) {
            $hasMajority => $tops[0],
            // 異なる判定が出ていて過半数が作れない = 解釈が割れた
            $distinct > 1 => self::SPLIT,
            // 判定した人数が足りず過半数に届かない（誰も判定していない場合も含む）。
            // 意見は割れていないので「割れた」ではなく材料不足と同じ扱いにする
            default => self::INSUFFICIENT,
        };

        // 代表行は「確定した判定を出した最も若い番号の判定者」の行。
        // 割れて split に確定したときは誰もその値を出していないため、
        // 先頭の判定者（1人目 = Base）の行を採る。人数1のときは常にその1件になる。
        $representative = null;
        foreach ($votes as $panelist => $verdict) {
            if ($verdict === $decided) {
                $representative = $panelist;
                break;
            }
        }
        $representative ??= array_key_first($rows);

        $voted = count($rows);
        $decidedBy = match (true) {
            $hasMajority => 'majority',
            $distinct > 1 => 'split',
            default => 'insufficient',
        };

        $verdictRow = $rows[$representative];
        $verdictRow['verdict'] = $decided;
        $verdictRow['consensus'] = [
            'panel_size' => $panelSize,
            // 実際にこの主張を判定した人数（言及しなかった判定者は含まない）
            'voted' => $voted,
            'counts' => $counts,
            // 多数決で確定したときの賛成人数。割れた・支持が足りないときは人数に意味が無いため null
            'agreed' => $hasMajority ? $counts[$decided] : null,
            // 全員が判定し、かつ全員が同じ判定だったか
            'is_unanimous' => $distinct === 1 && $voted === $panelSize,
            'decided_by' => $decidedBy,
            // 表示文言は画面・書き出しで組み立てず、確定したここで作る（両者で文言がずれないように）
            'summary' => match ($decidedBy) {
                'majority' => "{$voted}人中{$counts[$decided]}人が一致",
                'split' => "{$voted}人の判定が分かれた（材料を足しても解消しないため、面接で事実を確認する論点）",
                default => "{$voted}人しか判定しておらず、過半数に届かなかった",
            },
            'aggregated' => true,
            'opinions' => $panelOpinions,
        ];

        return $verdictRow;
    }

    /**
     * 合議できない行（主張との対応が不明・同じ判定者の重複行）をそのまま残す。
     * 判定は書き換えず、合議の対象外であることだけを添える。
     *
     * @param  array<string, mixed>  $row
     * @param  array{perspective: VerdictPerspective, output: array<string, mixed>}  $opinion
     * @return array<string, mixed>
     */
    private function passThrough(array $row, array $opinion, int $panelSize): array
    {
        $row['consensus'] = [
            'panel_size' => $panelSize,
            'aggregated' => false,
            'opinions' => [$this->opinionOf($opinion['perspective'], $row)],
        ];

        return $row;
    }

    /**
     * 判定者1人ぶんの意見。
     *
     * 判定が割れたとき、表に出るのは代表1人ぶんの行だけになる。判定者は観点が違えば
     * 突き合わせた材料も違うため、根拠だけでなく「何を見てそう判断したか」も各自のものを残す。
     * これが無いと、公式データを見て矛盾と判定した意見から、その材料へ辿れなくなる。
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function opinionOf(VerdictPerspective $perspective, array $row): array
    {
        return [
            'perspective' => $perspective->value,
            'label' => $perspective->label(),
            'verdict' => $row['verdict'] ?? null,
            'rationale' => $row['rationale'] ?? '',
            'checked_against' => $row['checked_against'] ?? '',
            'source_ids' => $row['source_ids'] ?? [],
        ];
    }
}
