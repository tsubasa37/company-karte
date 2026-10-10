<?php

namespace Tests\Unit;

use App\Services\Ai\CodexBinaryLocator;
use App\Services\Ai\Driver\AiRequest;
use App\Services\Ai\Driver\CodexDriver;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * codex exec --json の stdout（JSONL）からトークン数を拾う部分。
 *
 * fixture は実機の codex 0.152.1 を極小プロンプトで1回走らせて採った実測（thread_id は伏せた）。
 * 拾えないときに 0 で埋めず null にすること、黙らずログに残すことを固定する。
 */
class CodexDriverUsageParseTest extends TestCase
{
    private const BIN = '/opt/fake/node/bin/codex';

    /** 実測した turn.completed.usage の値（fixture と一致させる） */
    private const MEASURED = [
        'input_tokens' => 23641,
        'cached_input_tokens' => 0,
        'cache_write_input_tokens' => 0,
        'output_tokens' => 15,
        'reasoning_output_tokens' => 0,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.codex.bin' => self::BIN]);
    }

    private function driver(): CodexDriver
    {
        return new CodexDriver(new CodexBinaryLocator([]));
    }

    private function request(): AiRequest
    {
        return new AiRequest(['type' => 'object'], 'プロンプト本文', 'gpt-test', [], 42);
    }

    /** codex が stdout に $stdout を出し、-o のファイルに $lastMessage を書く実行を模す */
    private function fakeRun(string $stdout, string $lastMessage = '{"result":"最終回答"}', int $exitCode = 0): void
    {
        Process::fake(function (PendingProcess $process) use ($stdout, $lastMessage, $exitCode) {
            $args = $process->command;
            file_put_contents($args[array_search('--output-last-message', $args, true) + 1], $lastMessage);

            return Process::result(output: $stdout, exitCode: $exitCode);
        });
    }

    private function fixture(): string
    {
        return file_get_contents(base_path('tests/fixtures/codex-exec-events.jsonl'));
    }

    public function test_実測のjsonlからusageを共通の意味に正規化する(): void
    {
        $this->fakeRun($this->fixture());

        $tokens = $this->driver()->run($this->request())->tokens;

        $this->assertSame(23641, $tokens->input, 'input は cached・cacheWrite を含む総入力');
        $this->assertSame(0, $tokens->cached);
        $this->assertSame(0, $tokens->cacheWrite);
        $this->assertSame(15, $tokens->output, 'output は reasoning を含む課金対象の全出力');
        $this->assertSame(0, $tokens->reasoning);
        // 生値も残す（正規化の検証と将来の再計算のため）
        $this->assertSame(self::MEASURED, $tokens->raw);
    }

    public function test_結果はjsonl途中のagent_messageではなく出力ファイルから読む(): void
    {
        // 途中の agent_message にも出力スキーマが掛かるため、最終回答でないものを拾う恐れがある
        $this->fakeRun($this->fixture(), '{"result":"最終回答"}');

        $this->assertSame(['result' => '最終回答'], $this->driver()->run($this->request())->data);
    }

    public function test_jsonでない行が混ざってもusageを拾う(): void
    {
        // codex は警告行を stdout に混ぜることがある。JSONL として読めない行は読み飛ばす
        $this->fakeRun("warning: something\n".$this->fixture()."\n");

        $this->assertSame(23641, $this->driver()->run($this->request())->tokens->input);
    }

    public function test_turn_completedが無ければトークンはnullにして理由をログに残す(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'turn.completed がありません'));

        $this->fakeRun('{"type":"thread.started","thread_id":"x"}');

        // 本処理（-o のファイル）は続ける。トークンだけ「取れていない」を 0 と区別して null にする
        $response = $this->driver()->run($this->request());

        $this->assertNull($response->tokens);
        $this->assertSame(['result' => '最終回答'], $response->data);
    }

    public function test_usageの形が違えばトークンはnullにして理由をログに残す(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'usage が配列ではありません'));

        $this->fakeRun('{"type":"turn.completed","usage":"unexpected"}');

        $this->assertNull($this->driver()->run($this->request())->tokens);
    }

    public function test_turn_failedのメッセージを例外に添える(): void
    {
        // sandbox やモデル側の失敗は標準エラーに出ず、イベントにしか出ないことがある
        $this->fakeRun(
            '{"type":"turn.failed","error":{"message":"usage limit reached"}}',
            exitCode: 1,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Codex failed: usage limit reached');

        $this->driver()->run($this->request());
    }

    public function test_errorイベントのメッセージも標準エラーに添える(): void
    {
        Process::fake(function (PendingProcess $process) {
            return Process::result(
                output: '{"type":"error","message":"stream disconnected"}',
                errorOutput: 'exec failed',
                exitCode: 1,
            );
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Codex failed: exec failed / stream disconnected');

        $this->driver()->run($this->request());
    }
}
