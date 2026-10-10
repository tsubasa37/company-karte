<?php

namespace App\Enums;

/**
 * P2（裏取り判定）を複数人で行うときの、判定者ごとの観点。
 *
 * 同じプロンプトを人数分回しても codex は同じ答えを返しやすく（model_reasoning_effort=low 固定）、
 * 枠を人数倍消費するだけになる。そこで「どの材料を突き合わせの起点にするか」だけを変えて
 * 判定を独立させる。断定語の禁止・単発の口コミを根拠にしない等の判定基準は全員共通で、
 * 観点によって基準を緩めることはしない。
 *
 * cases() の並び順がそのままパネルの追加順（1人目 → 2人目 → 3人目）。
 * 先頭の Base は現行プロンプトそのままなので、人数1のときは多重化前と同じ手順で判定される。
 */
enum VerdictPerspective: string
{
    case Base = 'base';
    case Official = 'official';
    case Review = 'review';

    public function label(): string
    {
        return match ($this) {
            self::Base => '基本',
            self::Official => '公式データ重視',
            self::Review => '口コミ重視',
        };
    }

    /**
     * プロンプトに差し込む観点の指示。
     * Base は追加指示なし（空文字）で、多重化前のプロンプトと同一の内容になる。
     */
    public function instruction(): string
    {
        return match ($this) {
            self::Base => '',
            self::Official => <<<'TXT'
                この判定では、突き合わせの起点を公式データ（EDINET・しょくばらぼ・gBizINFO）に置いてください。
                公式数値で確認できる主張は公式数値で判定し、口コミは補助的な材料として扱います。
                公式データに該当する記載が無い主張は、口コミだけで無理に判定せず unverifiable にしてください。
                TXT,
            self::Review => <<<'TXT'
                この判定では、突き合わせの起点を口コミ（現場の証言）に置いてください。
                求人票の記載が現場の証言と食い違っていないかを先に確認します。
                ただし「口コミ1件だけを根拠に contradict にしない」「2年より前の口コミだけを根拠に contradict にしない」は
                そのまま厳守してください（起点を変えるだけで、判定の基準は緩めません）。
                TXT,
        };
    }

    /**
     * 人数ぶんの観点（cases() の先頭から順に）。
     * 用意した観点より多い人数は指定できない（黙って丸めず例外にする）。
     *
     * @return array<int, self>
     */
    public static function forPanel(int $size): array
    {
        if ($size < 1 || $size > self::maxPanelSize()) {
            throw new \InvalidArgumentException(
                '裏取りの人数は 1〜'.self::maxPanelSize()." 人で指定してください（指定値: {$size}）",
            );
        }

        return array_slice(self::cases(), 0, $size);
    }

    /** 選べる最大人数（用意した観点の数）。画面の選択肢とバリデーションの上限に使う */
    public static function maxPanelSize(): int
    {
        return count(self::cases());
    }

    /**
     * 画面の人数セレクタに出す選択肢。
     *
     * @return array<int, array{value: int, label: string}>
     */
    public static function panelOptions(): array
    {
        $options = [];
        foreach (range(1, self::maxPanelSize()) as $size) {
            $names = array_map(fn (self $p) => $p->label(), self::forPanel($size));
            $options[] = [
                'value' => $size,
                'label' => $size === 1 ? '1人（既定）' : "{$size}人（".implode(' / ', $names).'）',
            ];
        }

        return $options;
    }
}
