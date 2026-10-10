<?php

namespace App\Services\Ai;

/**
 * codex 実行ファイルの場所を決める。
 *
 * codex は node 製の CLI で、nvm 配下に入ることが多い。一方 queue:work（分析ワーカー）は
 * ログインシェルを通らないため PATH が薄く、`codex` という名前だけでは見つからない。
 * そのため従来は CODEX_BIN に絶対パスを手で書かせていたが、置き場所は数パターンしかないので、
 * ここで機械的に決めて設定を1行減らす。
 *
 * これは「見つからなければ別の手段で誤魔化す」フォールバックではない。
 * 候補を順に確かめ、1つも無ければ**例外にして止める**（サイレント失敗はしない）。
 * CODEX_BIN が設定されている場合は、その明示指定を常に優先する。
 */
class CodexBinaryLocator
{
    /**
     * 探索する場所（優先順）。nvm は複数バージョンが同居しうるのでグロブで拾い、
     * 新しいバージョンから試す。
     *
     * @var array<int, string>
     */
    private const CANDIDATES = [
        '~/.nvm/versions/node/*/bin/codex',
        '/opt/homebrew/bin/codex',
        '/usr/local/bin/codex',
        '/usr/bin/codex',
    ];

    /** 同一リクエスト内で何度も探索しないための記憶 */
    private ?string $resolved = null;

    /**
     * @param  array<int, string>|null  $candidates  探索先の上書き（テスト用。通常は null で既定を使う）
     */
    public function __construct(private readonly ?array $candidates = null) {}

    /**
     * 実行可能な codex の絶対パス、または明示指定された値を返す。
     *
     * @throws \RuntimeException 候補のどこにも見つからない場合
     */
    public function locate(): string
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $configured = config('services.codex.bin');

        // 明示指定は常に優先する（別バージョンを試したい・独自の場所に置いた等）
        if (filled($configured)) {
            return $this->resolved = $configured;
        }

        foreach ($this->candidates ?? self::CANDIDATES as $pattern) {
            $found = $this->firstExecutable($pattern);

            if ($found !== null) {
                return $this->resolved = $found;
            }
        }

        throw new \RuntimeException(
            'codex 実行ファイルが見つかりません。'
            .'`npm i -g @openai/codex` で導入して `codex login` を済ませるか、'
            .'CODEX_BIN に絶対パス（`which codex` の出力）を指定してください。'
        );
    }

    /**
     * パターンに一致する実行可能ファイルのうち、最も新しいバージョンのものを返す。
     *
     * 並べ替えに文字列比較は使えない。nvm のディレクトリ名は v18.20.4 / v9.11.0 のような形で、
     * 辞書式に降順で並べると v9 が v22 より前に来て、古い codex を掴んでしまう。
     * バージョン番号として比較する。
     */
    private function firstExecutable(string $pattern): ?string
    {
        $home = (string) getenv('HOME');
        $paths = glob(str_replace('~', $home, $pattern)) ?: [];
        usort($paths, fn (string $a, string $b) => version_compare($this->versionOf($b), $this->versionOf($a)));

        foreach ($paths as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * パスに含まれる vX.Y.Z を取り出す。含まれない場合（Homebrew 等の固定パス）は
     * 比較の必要が無いので空文字を返す。
     */
    private function versionOf(string $path): string
    {
        return preg_match('#/v(\d+(?:\.\d+)*)/#', $path, $m) === 1 ? $m[1] : '';
    }
}
