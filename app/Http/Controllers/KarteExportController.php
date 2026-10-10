<?php

namespace App\Http\Controllers;

use App\Actions\ExportKarteMarkdownAction;
use App\Models\Company;
use Illuminate\Http\Response;

class KarteExportController extends Controller
{
    /** カルテを Markdown ファイルとしてダウンロードさせる（Obsidian 等への持ち出し用） */
    public function __invoke(Company $company, ExportKarteMarkdownAction $action): Response
    {
        $markdown = $action->execute($company);

        if ($markdown === null) {
            return response('分析後にエクスポートできます', 422, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        // 企業名はクリップ由来（外部ページからのAI抽出値）が入り得るため、ヘッダに素で埋めない。
        // 引用符・改行・制御文字を除去し、非ASCIIは RFC 5987 の filename* で渡す
        $name = (string) preg_replace('/[\x00-\x1f\x7f"\\\\\/]+/u', '', $company->name);
        $filename = "{$name}-karte-".now()->format('Ymd').'.md';
        // ASCII フォールバック（filename* 非対応クライアント用）。社名が全部非ASCIIなら社名部を落とす
        $asciiName = trim((string) preg_replace('/[^\x20-\x7e]+/', '_', $name), '_ ');
        $ascii = ($asciiName !== '' ? "{$asciiName}-" : '').'karte-'.now()->format('Ymd').'.md';

        return response($markdown, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$ascii}\"; filename*=UTF-8''".rawurlencode($filename),
        ]);
    }
}
