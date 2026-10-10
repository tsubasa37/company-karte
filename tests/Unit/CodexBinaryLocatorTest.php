<?php

namespace Tests\Unit;

use App\Services\Ai\CodexBinaryLocator;
use Tests\TestCase;

/**
 * codex 実行ファイルの解決。
 *
 * 「見つからないときに黙って別の手段へ流れない」ことが要点なので、例外側を重点的に見る。
 * 探索先はコンストラクタで差し替えて、codex が入っていない環境（CI）でも成立させる。
 */
class CodexBinaryLocatorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/codex-locator-'.uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        // バージョンディレクトリごと消す（nvm を模した入れ子を作るため）
        exec('rm -rf '.escapeshellarg($this->dir));

        parent::tearDown();
    }

    private function fakeBinary(string $name, bool $executable = true): string
    {
        $path = $this->dir.'/'.$name;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, "#!/bin/sh\n");
        chmod($path, $executable ? 0755 : 0644);

        return $path;
    }

    public function test_明示指定があればそれを優先する(): void
    {
        config(['services.codex.bin' => '/opt/custom/codex']);
        // 探索先にも実体があるが、明示指定を上書きしない
        $this->fakeBinary('codex');

        $locator = new CodexBinaryLocator([$this->dir.'/codex']);

        $this->assertSame('/opt/custom/codex', $locator->locate());
    }

    public function test_未設定なら探索先から実行ファイルを見つける(): void
    {
        config(['services.codex.bin' => null]);
        $path = $this->fakeBinary('codex');

        $locator = new CodexBinaryLocator([$this->dir.'/codex']);

        $this->assertSame($path, $locator->locate());
    }

    public function test_グロブで複数一致したら新しいバージョンを選ぶ(): void
    {
        config(['services.codex.bin' => null]);
        // nvm と同じ「バージョンがディレクトリ名」の構造で並べる。
        // 辞書式に降順で並べると v9 が v22 より前に来て、古い codex を掴んでしまう
        $this->fakeBinary('v9.11.0/bin/codex');
        $this->fakeBinary('v18.20.4/bin/codex');
        $newest = $this->fakeBinary('v22.11.0/bin/codex');

        $locator = new CodexBinaryLocator([$this->dir.'/*/bin/codex']);

        $this->assertSame($newest, $locator->locate());
    }

    public function test_実行権限が無いファイルは候補にしない(): void
    {
        config(['services.codex.bin' => null]);
        $this->fakeBinary('codex', executable: false);

        $locator = new CodexBinaryLocator([$this->dir.'/codex']);

        $this->expectException(\RuntimeException::class);

        $locator->locate();
    }

    public function test_どこにも無ければ導入方法を示して例外にする(): void
    {
        config(['services.codex.bin' => null]);

        $locator = new CodexBinaryLocator([$this->dir.'/does-not-exist']);

        $this->expectException(\RuntimeException::class);
        // 黙って諦めず、次に何をすればよいかを伝える
        $this->expectExceptionMessage('codex login');

        $locator->locate();
    }

    public function test_解決結果は記憶して再探索しない(): void
    {
        config(['services.codex.bin' => null]);
        $path = $this->fakeBinary('codex');
        $locator = new CodexBinaryLocator([$this->dir.'/codex']);

        $this->assertSame($path, $locator->locate());

        // 実体が消えても、一度解決した値を返し続ける（1リクエスト内での再探索を避ける）
        unlink($path);

        $this->assertSame($path, $locator->locate());
    }
}
