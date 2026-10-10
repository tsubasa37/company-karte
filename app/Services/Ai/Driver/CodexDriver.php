<?php

namespace App\Services\Ai\Driver;

use App\Enums\AiProvider;
use App\Services\Ai\CodexBinaryLocator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * codex exec を構造化スキーマ付きでサブプロセス実行し、JSON をデコードして返す。
 *
 * - ChatGPTサブスク認証で走るため API 課金は発生しない（¥0）。
 * - 構造化出力の root は必ず object（配列は items 等でラップ・additionalProperties:false）。
 * - 失敗時はサイレントにせず例外を投げる。
 * - --json で stdout に出る JSONL の turn.completed.usage からトークン数を拾う。
 *   拾えなければ tokens は null（「取れていない」を 0 と区別する）。ただし黙らせず Log::warning に理由を残す。
 */
class CodexDriver implements AiDriver
{
    /** 実行ファイルの場所は Locator が決める（CODEX_BIN 未設定でも nvm 配下等から解決する） */
    public function __construct(private readonly CodexBinaryLocator $binary = new CodexBinaryLocator) {}

    public function run(AiRequest $request): AiResponse
    {
        // プロンプトは stdin 経由で渡すため、空だと codex が入力なしで走ってしまう。
        // サイレントに空実行させず、ここで落とす。
        if (trim($request->prompt) === '') {
            throw new \InvalidArgumentException('Codex prompt must not be empty');
        }

        // スキーマ/出力は .json 拡張子が要るが、tempnam の派生パス（xxx.json）は
        // 非原子的に作られ 0644 になり他ユーザーから読める。
        // そこで 0700 の専用ディレクトリを掘り、その中に固定名で置く。
        $workDir = $this->makeSecureTempDir();
        $schemaFile = $workDir.DIRECTORY_SEPARATOR.'schema.json';
        $outFile = $workDir.DIRECTORY_SEPARATOR.'out.json';

        try {
            file_put_contents($schemaFile, json_encode($request->schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            file_put_contents($outFile, '');

            $bin = $this->binary->locate();

            // codex は node 製 CLI。絶対パス指定のときは同じディレクトリの node を
            // 確実に使わせるため PATH 先頭に追加（queue:work 等 PATH が薄い環境でも動くように）。
            // 作業ディレクトリは空の一時ディレクトリにする。プロジェクト直下で走らせると codex が
            // AGENTS.md / CLAUDE.md 等を文脈として読み込み、分析と無関係な指示とトークンが混ざる
            $pending = Process::timeout($request->timeout)->path($workDir);
            if (str_contains($bin, DIRECTORY_SEPARATOR)) {
                $binDir = dirname($bin);
                $currentPath = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';
                $pending = $pending->env(['PATH' => $binDir.PATH_SEPARATOR.$currentPath]);
            }

            $args = [
                $bin,
                'exec',
                // イベントを stdout に JSONL で出させる（turn.completed.usage がトークン数の出所）。
                // codex 0.152.1 の `codex exec --help` に「Print events to stdout as JSONL」と明記。
                // イベント型は https://github.com/openai/codex/blob/main/codex-rs/exec/src/exec_events.rs（2026-09-03 確認）
                '--json',
                '--sandbox',
                'read-only',
                '--skip-git-repo-check',
                '--color',
                'never',
                '-c',
                'model_reasoning_effort=low',
            ];

            // モデル指定（未設定なら codex の既定モデルに任せる）
            if (filled($request->model)) {
                $args[] = '-m';
                $args[] = $request->model;
            }

            // プロンプト本文（職務経歴・口コミ等）を argv に載せると ps から全文が読めるため、
            // PROMPT 引数は '-' にして stdin から渡す。
            array_push(
                $args,
                '--output-schema',
                $schemaFile,
                '--output-last-message',
                $outFile,
                '-',
            );

            $process = $pending->input($request->prompt)->run($args);
            $events = $this->events($process->output());

            if ($process->exitCode() !== 0) {
                throw new \RuntimeException('Codex failed: '.$this->failureMessage($events, $process->errorOutput()));
            }

            // 結果は JSONL 中の agent_message ではなく -o のファイルから読む。
            // 途中の agent_message にも出力スキーマが掛かるため、最終回答でないものを拾う恐れがある
            $output = file_get_contents($outFile);
            if ($output === '' || $output === false) {
                throw new \RuntimeException('Codex produced empty output');
            }

            $data = json_decode($output, true);
            if (! is_array($data)) {
                throw new \RuntimeException('Invalid JSON from codex: '.$output);
            }

            return new AiResponse($data, $this->tokens($events), AiProvider::Codex, $request->model);
        } finally {
            $this->removeTempDir($workDir);
        }
    }

    /**
     * stdout の JSONL をイベントの配列にする。
     *
     * JSON でない行（codex の警告など）は読み飛ばす。結果の正は -o のファイルなので、
     * ここで落とすとトークン数が取れないだけの理由で分析全体を止めてしまう。
     *
     * @return array<int, array<string, mixed>>
     */
    private function events(string $stdout): array
    {
        $events = [];

        foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        return $events;
    }

    /**
     * turn.completed の usage を共通の意味に正規化する（input は cached・cacheWrite を含む総入力）。
     *
     * 欄の名前と型は codex-rs/exec/src/exec_events.rs の Usage 構造体に合わせた
     * （https://github.com/openai/codex/blob/main/codex-rs/exec/src/exec_events.rs・2026-09-03 確認）。
     * 拾えなくても本処理（-o のファイル）は続けるが、黙って null にはせず理由をログに残す。
     *
     * @param  array<int, array<string, mixed>>  $events
     */
    private function tokens(array $events): ?TokenUsage
    {
        $usage = null;

        foreach ($events as $event) {
            if (($event['type'] ?? null) === 'turn.completed') {
                $usage = $event['usage'] ?? null;
            }
        }

        if (! is_array($usage)) {
            Log::warning(
                'codex のトークン数を取得できませんでした（'
                .($usage === null ? '--json の出力に turn.completed がありません' : 'turn.completed の usage が配列ではありません')
                .'）。codex のバージョンでイベントの形が変わった可能性があります'
            );

            return null;
        }

        return new TokenUsage(
            input: $this->int($usage['input_tokens'] ?? null),
            cached: $this->int($usage['cached_input_tokens'] ?? null),
            cacheWrite: $this->int($usage['cache_write_input_tokens'] ?? null),
            output: $this->int($usage['output_tokens'] ?? null),
            reasoning: $this->int($usage['reasoning_output_tokens'] ?? null),
            raw: $usage,
        );
    }

    /** 無い欄は null（0 で埋めない） */
    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * 失敗の理由。標準エラーに加え、turn.failed / error イベントの message も添える
     * （sandbox やモデル側の失敗は標準エラーに出ず、イベントにしか出ないことがある）。
     *
     * @param  array<int, array<string, mixed>>  $events
     */
    private function failureMessage(array $events, string $errorOutput): string
    {
        $parts = [trim($errorOutput)];

        foreach ($events as $event) {
            $message = match ($event['type'] ?? null) {
                'turn.failed' => $event['error']['message'] ?? null,
                'error' => $event['message'] ?? null,
                default => null,
            };

            if (is_string($message) && trim($message) !== '') {
                $parts[] = trim($message);
            }
        }

        $parts = array_unique(array_filter($parts));

        return $parts === [] ? '（標準エラーにもイベントにも理由がありません）' : implode(' / ', $parts);
    }

    /**
     * 他ユーザーから読めない 0700 の一時ディレクトリを作る。
     *
     * PHP には mkdtemp 相当が無いため、tempnam で原子的にユニーク名を確保し、
     * 消してから同名で mkdir する定石を使う（mkdir は既存名なら false を返すので
     * 名前を横取りされた場合は例外になる）。
     */
    private function makeSecureTempDir(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'karte_codex_');
        if ($base === false) {
            throw new \RuntimeException('Failed to allocate temp path for codex');
        }

        unlink($base);

        if (! mkdir($base, 0700) || ! is_dir($base)) {
            throw new \RuntimeException('Failed to create temp dir for codex: '.$base);
        }

        return $base;
    }

    /**
     * 一時ディレクトリを中身ごと削除する（中のファイル → ディレクトリの順）。
     *
     * finally から呼ぶため、ここで失敗を表に出すと本来の失敗理由を覆い隠す。
     * PHP の警告は Laravel の例外ハンドラで ErrorException になり、finally 中に投げると
     * 元の例外（codex 失敗など、原因そのもの）を置き換えてしまうため、削除系の警告は抑止する。
     * これはサイレント失敗の許容ではなく、原因である本来の例外を優先させるための措置。
     * 後始末は宣言どおり best-effort とし、判断材料になるエラーは呼び出し側の例外に任せる。
     */
    private function removeTempDir(string $dir): void
    {
        // リンク先がディレクトリだと is_dir が true になり、辿ると一時ディレクトリ外を
        // 消してしまう。リンクは中身を見ずリンク自体だけを消す。
        if (is_link($dir)) {
            @unlink($dir);

            return;
        }

        if (! is_dir($dir)) {
            return;
        }

        foreach (@scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$name;
            // リンクかどうかの判定は再帰先の入口で行う（リンクならそこで辿らず消える）
            if (is_dir($path)) {
                $this->removeTempDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
