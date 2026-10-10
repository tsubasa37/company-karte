<?php

use App\Services\UrlNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 既存 source_documents.url を UrlNormalizer の正規化後の値に揃えるデータ移行。
 *
 * 正規化前は Bing News の apiclick リンク（tid が取得ごとに変わる）や utm 付きURLが
 * そのまま保存されており、同じページが別レコードになって上書き（改訂差分）が働かない。
 */
return new class extends Migration
{
    public function up(): void
    {
        $normalizer = new UrlNormalizer;

        $rows = DB::table('source_documents')
            ->whereNotNull('url')
            ->orderBy('id')
            ->get(['id', 'company_id', 'type', 'url']);

        foreach ($rows as $row) {
            $normalized = $normalizer->normalize((string) $row->url);

            if ($normalized === $row->url) {
                continue;
            }

            // 正規化すると同一企業内で (url, type) が既存行と衝突する場合がある。
            // どちらを残すかは本文を見て本人が決めることなので、統合も削除もせず
            // 元のURLのまま残し、衝突したことを警告として明示する（黙って消さない）。
            $conflictId = DB::table('source_documents')
                ->where('company_id', $row->company_id)
                ->where('type', $row->type)
                ->where('url', $normalized)
                ->where('id', '!=', $row->id)
                ->value('id');

            if ($conflictId !== null) {
                Log::warning('URL正規化で既存ソースと衝突したため元のURLを維持しました', [
                    'source_document_id' => $row->id,
                    'conflicting_source_document_id' => $conflictId,
                    'company_id' => $row->company_id,
                    'type' => $row->type,
                    'url' => $row->url,
                    'normalized_url' => $normalized,
                ]);

                continue;
            }

            DB::table('source_documents')->where('id', $row->id)->update(['url' => $normalized]);
        }
    }

    public function down(): void
    {
        // 追跡パラメータの除去・リダイレクタの展開は不可逆（元のURLを保存していない）。
        // 取り消せないことを記録として残し、URL は正規化後のまま据え置く。
        Log::warning('source_documents.url の正規化は取り消せません（元のURLは保持していないため据え置きます）');
    }
};
