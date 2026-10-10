<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Http;
use Smalot\PdfParser\Parser;

/**
 * 厚労省「労働基準関係法令違反に係る公表事案」の最新PDFと登録企業名を突合する。
 * 一致は「社名一致あり（要確認）」の事実だけを labor_note に残し、断定はしない。
 * 一致が消えたときは消えた注記を labor_note_history に追記する（公表リストの掲載期間は
 * 約1年でローテーションするため、過去に載っていた事実が黙って消えないようにする）。
 * ページ構成変化・取得失敗は例外で明示する（サイレント失敗禁止）。
 */
class LaborViolationChecker
{
    /** PDFはページ本文より重いため、テキスト取り込みより長い締め切りを取る */
    private const PDF_TIMEOUT_SECONDS = 60;

    /** 履歴に残す「消えた理由」 */
    private const REASON_NO_MATCH = '再突合で公表事案PDFに社名一致なし（掲載期間の終了・PDF表記の変更いずれの可能性もある）';

    private const REASON_TOO_SHORT = '正規化後3文字未満のため突合対象外';

    public function __construct(
        private readonly CompanyNameNormalizer $names,
        private readonly GuardedHttpFetcher $fetcher,
    ) {}

    public function checkAll(): void
    {
        $this->matchCompanies($this->fetchLatestPdfText());
    }

    /**
     * PDFのテキストと全登録企業を突合し、結果を各企業に保存する。
     * PDFは文字間に空白が入り、全角英数・半角カナ・中黒の揺れもあるため、
     * needle（登録社名）と haystack（PDF本文）の両方に同じ TextNormalizer を当てる。
     * 片側だけに当てると、これまで一致していた社名が一致しなくなる。
     */
    public function matchCompanies(string $pdfText): void
    {
        $flat = TextNormalizer::normalize($pdfText);

        // 突合は本人の操作による編集ではないため、labor 系の書き込みで updated_at を動かさない
        Company::withoutTimestamps(function () use ($flat): void {
            foreach (Company::all() as $company) {
                $needle = $this->names->normalize($company->name);

                // 正規化後2文字以下の社名は誤ヒットが多発するため突合しない（結果は「一致なし」扱い）
                if (mb_strlen($needle) < 3) {
                    $this->clearNote($company, self::REASON_TOO_SHORT);

                    continue;
                }

                $pos = mb_strpos($flat, $needle);

                if ($pos === false) {
                    $this->clearNote($company, self::REASON_NO_MATCH);

                    continue;
                }

                $company->update([
                    'labor_note' => $this->note($flat, $pos),
                    'labor_checked_at' => now(),
                ]);
            }
        });
    }

    /**
     * 注記を消す。厚労省の公表リストは掲載期間が約1年でローテーションするため、
     * 一度一致した社名は次回の突合で一致しなくなる。消したまま何も残さないと
     * 「過去に載っていた」事実が黙って消えるので、消えた内容を履歴に追記する。
     */
    private function clearNote(Company $company, string $reason): void
    {
        $previous = $company->labor_note;

        $attributes = ['labor_note' => null, 'labor_checked_at' => now()];

        if (filled($previous)) {
            $history = $company->labor_note_history ?? [];
            $history[] = [
                'note' => $previous,
                'reason' => $reason,
                'cleared_at' => now()->toIso8601String(),
            ];
            $attributes['labor_note_history'] = $history;
        }

        $company->update($attributes);
    }

    private function note(string $flat, int $position): string
    {
        $excerpt = mb_substr($flat, max(0, $position - 20), 140);

        return '公表事案PDFに社名一致（同名の別法人の可能性あり・要原文確認）。'
            ."抜粋: {$excerpt}…（出典: ".$this->indexUrl().'）';
    }

    private function fetchLatestPdfText(): string
    {
        $page = Http::timeout(30)
            ->withHeaders(['User-Agent' => 'company-karte/1.0 (personal research)'])
            ->get($this->indexUrl());

        if (! $page->successful()) {
            throw new \RuntimeException(
                "厚労省の公表事案ページの取得に失敗しました（HTTP {$page->status()}）。"
                .'掲載先が移動した可能性があります。LABOR_INDEX_URL で上書きできます（現在: '.$this->indexUrl().'）'
            );
        }

        $url = $this->findPublicationPdfUrl($page->body());

        // 取得先は掲載ページのHTML由来なので、同一オリジン確認だけでは足りない。
        // 解決先IPの検証とそのIPへの接続固定・リダイレクトの1ホップずつの再検証は
        // GuardedHttpFetcher に任せる（ホスト名のまま取りに行くとリバインディングで抜けられる）
        $pdf = $this->fetcher->fetchBody($url, self::PDF_TIMEOUT_SECONDS);

        $text = (new Parser)->parseContent($pdf)->getText();
        if (trim($text) === '') {
            throw new \RuntimeException('公表事案PDFからテキストを抽出できませんでした');
        }

        return $text;
    }

    /**
     * 公表事案PDFのURLを返す。ページ内の最初の .pdf を拾うと、ナビゲーションや関連資料の
     * PDFを掴んで全企業の突合結果を誤らせるため、リンク文言で公表事案のものに絞る。
     * 該当が無ければ例外（ページ構成の変化を握り潰さない）。
     */
    private function findPublicationPdfUrl(string $html): string
    {
        preg_match_all('#<a[^>]+href="([^"]+\.pdf)"[^>]*>(.*?)</a>#is', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as [$_, $href, $label]) {
            $text = strip_tags($label);
            if (str_contains($text, '公表') && (str_contains($text, '違反') || str_contains($text, '送検'))) {
                return $this->resolveUrl($href);
            }
        }

        throw new \RuntimeException(
            '厚労省ページから公表事案PDF（「労働基準関係法令違反に係る公表事案」）のリンクを特定できませんでした'
            .'（ページ構成が変わった可能性。出典: '.$this->indexUrl().'）'
        );
    }

    /**
     * href を掲載ページ基準の絶対URLにし、掲載ページと同一オリジンであることを確かめる。
     * href は取得したHTML由来（改ざん・書き換えを受けうる）ため、任意ホストや平文HTTPへ
     * 取得しに行かせない。基準は LABOR_INDEX_URL 側に合わせる（掲載先が移ればPDFも同じ先にある）。
     */
    private function resolveUrl(string $href): string
    {
        $base = parse_url($this->indexUrl());

        if (! is_array($base) || ! isset($base['scheme'], $base['host'])) {
            throw new \RuntimeException('掲載ページのURL形式が不正です（LABOR_INDEX_URL）: '.$this->indexUrl());
        }

        $origin = $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '');

        $url = match (true) {
            preg_match('#^[a-z][a-z0-9+.\-]*://#i', $href) === 1 => $href,
            // プロトコル相対（//host/…）は掲載ページのスキームで解決する
            str_starts_with($href, '//') => $base['scheme'].':'.$href,
            str_starts_with($href, '/') => $origin.$href,
            default => $origin.rtrim(dirname($base['path'] ?? '/'), '/').'/'.$href,
        };

        $parts = parse_url($url);

        if (! is_array($parts)
            || mb_strtolower($parts['scheme'] ?? '') !== mb_strtolower($base['scheme'])
            || mb_strtolower($parts['host'] ?? '') !== mb_strtolower($base['host'])
            || ($parts['port'] ?? null) !== ($base['port'] ?? null)
        ) {
            throw new \RuntimeException(
                "公表事案PDFのリンク先が掲載ページと別オリジンのため取得しません: {$url}"
                .'（掲載ページ: '.$this->indexUrl().'）'
            );
        }

        return $url;
    }

    private function indexUrl(): string
    {
        return config('services.labor.index_url');
    }
}
