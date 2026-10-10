<?php

namespace App\Services\Ai;

use App\Enums\SourceOrigin;
use App\Models\SourceDocument;
use App\Services\Mail\MailMasker;

/**
 * AI に渡すソース本文を取り出す唯一の経路。
 *
 * メール由来（origin = mail）の本文だけは、取り込み時と同じマスクを掛けてから返す。
 * 取り込み時のマスクはメール抽出のプロンプトにしか掛かっておらず、原文は source_documents に
 * そのまま残る（画面で読むのは本人なので伏せない）。種別をスカウトから求人票・面接メモへ
 * 付け替えると、その原文が分析・条件抽出・口コミ分割・ブリーフィングのプロンプトへ入るため、
 * 読み出し側で必ずここを通す。
 *
 * 切り詰めは呼び出し元がマスク後の本文に対して行う。先に切ると電話番号が途中で切れて
 * マスクの照合から外れるため、順序は「マスク → 切り詰め」で固定する。
 */
class SourceMaterialText
{
    /** @var array<int, string> 文書ID => マスク適用後の本文（同じ本文を何度もマスクしないための記憶） */
    private array $memo = [];

    public function __construct(private readonly MailMasker $masker) {}

    /** AI に渡してよい本文。メール由来はマスク後、それ以外は原文のまま。 */
    public function of(SourceDocument $document): string
    {
        $raw = (string) $document->raw_text;

        if ($document->origin !== SourceOrigin::Mail) {
            return $raw;
        }

        $id = $document->getKey();

        if ($id === null) {
            return $this->masker->mask($raw);
        }

        return $this->memo[(int) $id] ??= $this->masker->mask($raw);
    }
}
