<?php

namespace Tests\Unit;

use App\Enums\AiProvider;
use App\Services\Ai\CodexBinaryLocator;
use App\Services\Ai\Driver\AiRequest;
use App\Services\Ai\Driver\CodexDriver;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * codex サブプロセス実行の組み立て。Process::fake で codex を起動せずに、
 * 引数・stdin・PATH・失敗時の例外が従来どおりであることを確かめる。
 */
class CodexDriverTest extends TestCase
{
    private const BIN = '/opt/fake/node/bin/codex';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.codex.bin' => self::BIN]);
    }

    private function request(string $prompt = 'プロンプト本文', ?string $model = 'gpt-test', int $timeout = 42): AiRequest
    {
        return new AiRequest(['type' => 'object'], $prompt, $model, [], $timeout);
    }

    private function driver(): CodexDriver
    {
        return new CodexDriver(new CodexBinaryLocator([]));
    }

    public function test_空のプロンプトはcodexを起動せず例外にする(): void
    {
        Process::fake();

        try {
            $this->driver()->run($this->request(prompt: "  \n"));
            $this->fail('空プロンプトは例外になるはず');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Codex prompt must not be empty', $e->getMessage());
        }

        Process::assertNothingRan();
    }

    public function test_引数とstdinとpathが従来どおり組み立てられ出力ファイルの_jsonを返す(): void
    {
        $seen = null;
        Process::fake(function (PendingProcess $process) use (&$seen) {
            $seen = $process;
            $args = $process->command;
            // 実行中だけ存在する一時ファイルの中身をここで確かめ、出力を書き込む
            $this->assertJsonStringEqualsJsonString('{"type":"object"}', file_get_contents($args[array_search('--output-schema', $args, true) + 1]));
            file_put_contents($args[array_search('--output-last-message', $args, true) + 1], '{"ok":true}');

            return Process::result();
        });

        $response = $this->driver()->run($this->request());

        $this->assertSame(['ok' => true], $response->data);
        $this->assertSame(AiProvider::Codex, $response->provider);
        $this->assertSame('gpt-test', $response->model);
        $this->assertNull($response->tokens, 'usage の無い実行はトークンを 0 で埋めず null にする');

        $args = $seen->command;
        $this->assertSame(
            [self::BIN, 'exec', '--json', '--sandbox', 'read-only', '--skip-git-repo-check', '--color', 'never', '-c', 'model_reasoning_effort=low', '-m', 'gpt-test'],
            array_slice($args, 0, 12),
        );
        $this->assertSame('--output-schema', $args[12]);
        $this->assertSame('--output-last-message', $args[14]);
        // プロンプトは argv ではなく stdin（ps から読めないように）
        $this->assertSame('-', end($args));
        $this->assertSame('プロンプト本文', $seen->input);
        $this->assertSame(42, $seen->timeout);
        // node 製 CLI なので、実行ファイルと同じディレクトリを PATH の先頭に付ける
        $this->assertStringStartsWith('/opt/fake/node/bin'.PATH_SEPARATOR, $seen->environment['PATH']);
        // 作業ディレクトリはスキーマを置いた一時ディレクトリ（プロジェクト直下だと AGENTS.md 等を文脈に読まれる）
        $this->assertSame(dirname($args[13]), $seen->path);
        $this->assertNotSame(base_path(), $seen->path);
    }

    public function test_モデル未指定なら_mを付けず既定モデルに任せる(): void
    {
        $seen = null;
        Process::fake(function (PendingProcess $process) use (&$seen) {
            $seen = $process;
            file_put_contents($process->command[array_search('--output-last-message', $process->command, true) + 1], '{"ok":true}');

            return Process::result();
        });

        $response = $this->driver()->run($this->request(model: null));

        $this->assertNotContains('-m', $seen->command);
        $this->assertNull($response->model);
    }

    public function test_終了コードが0以外なら標準エラーつきで例外にする(): void
    {
        Process::fake(fn () => Process::result(errorOutput: 'rate limit reached', exitCode: 1));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Codex failed: rate limit reached');

        $this->driver()->run($this->request());
    }

    public function test_出力が空なら例外にする(): void
    {
        Process::fake(fn () => Process::result());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Codex produced empty output');

        $this->driver()->run($this->request());
    }

    public function test_出力がjsonでなければ例外にする(): void
    {
        Process::fake(function (PendingProcess $process) {
            file_put_contents($process->command[array_search('--output-last-message', $process->command, true) + 1], 'not json');

            return Process::result();
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON from codex');

        $this->driver()->run($this->request());
    }
}
