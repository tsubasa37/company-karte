<?php

namespace App\Services\Edinet;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * EDINET API v2（金融庁・無料）のHTTPクライアント。
 * キー未設定・取得失敗は例外で明示する（サイレント失敗禁止）。
 */
class EdinetClient
{
    private const BASE = 'https://api.edinet-fsa.go.jp/api/v2';

    /**
     * 指定日の提出書類一覧（メタデータ）を返す。
     *
     * @return array<int, array<string, mixed>>
     */
    public function documentsFor(string $date): array
    {
        $response = $this->get(self::BASE.'/documents.json', ['date' => $date, 'type' => 2], 30);

        if (! $response->successful()) {
            throw new \RuntimeException("EDINET 書類一覧の取得に失敗しました（{$date} / HTTP {$response->status()}）");
        }

        return (array) ($response->json('results') ?? []);
    }

    /**
     * 書類の CSV(zip) をダウンロード・展開し、UTF-8 の CSV テキスト配列で返す。
     *
     * @return array<int, string>
     */
    public function documentCsvTexts(string $docId): array
    {
        $response = $this->get(self::BASE."/documents/{$docId}", ['type' => 5], 60);

        if (! $response->successful()) {
            throw new \RuntimeException("EDINET 書類CSVの取得に失敗しました（{$docId} / HTTP {$response->status()}）");
        }

        return $this->extractCsvTexts($response->body(), $docId);
    }

    /**
     * 購読キーはクエリ文字列で送るしかない（EDINET API v2 の仕様）。接続失敗時に Guzzle が
     * 例外文言へクエリ込みの URL を付けるため、キーを伏せてから詰め替える。伏せないと
     * companies.edinet_error・画面・failed_jobs・ログに平文で残る。元の例外は previous に繋がない
     * （繋ぐと伏せる前の文言がスタックトレース経由でログに出る）。
     *
     * @param  array<string, mixed>  $query
     */
    private function get(string $url, array $query, int $timeout): Response
    {
        $key = $this->key();

        try {
            return Http::timeout($timeout)->get($url, $query + ['Subscription-Key' => $key]);
        } catch (\Throwable $e) {
            $message = str_replace([$key, urlencode($key)], '***', $e->getMessage());

            throw new \RuntimeException('EDINET API に接続できませんでした: '.$message);
        }
    }

    /**
     * @return array<int, string>
     */
    private function extractCsvTexts(string $zipBinary, string $docId): array
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'edinet-');
        if ($zipPath === false) {
            throw new \RuntimeException('一時ファイルを作成できませんでした');
        }

        try {
            file_put_contents($zipPath, $zipBinary);

            $zip = new \ZipArchive;
            if ($zip->open($zipPath) !== true) {
                throw new \RuntimeException("EDINET 書類zipを展開できませんでした（{$docId}）");
            }

            $texts = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                // 有報本体のCSV（jpcrp*）だけ読む。監査報告書等（jpaud*）は不要
                if (str_ends_with($name, '.csv') && str_contains($name, 'jpcrp')) {
                    // EDINETのCSVは UTF-16LE（タブ区切り）
                    $texts[] = mb_convert_encoding((string) $zip->getFromIndex($i), 'UTF-8', 'UTF-16LE');
                }
            }
            $zip->close();

            if ($texts === []) {
                throw new \RuntimeException("EDINET 書類zipに有報CSVが含まれていません（{$docId}）");
            }

            return $texts;
        } finally {
            @unlink($zipPath);
        }
    }

    private function key(): string
    {
        $key = config('services.edinet.key');
        if (blank($key)) {
            throw new \RuntimeException(
                'EDINET_API_KEY が未設定です（https://api.edinet-fsa.go.jp/ でアカウント登録すると無料で発行できます）'
            );
        }

        return (string) $key;
    }
}
