<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureLocalHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LocalHostTest extends TestCase
{
    use RefreshDatabase;

    public function test_localhostは通過する(): void
    {
        $this->get('/companies')->assertStatus(200);
    }

    public function test_外部ホストのリクエストは403で弾く(): void
    {
        // Host を評価させるため絶対URLで指定する。
        // ($this->get($uri, ['Host' => ...]) のヘッダ配列は getHost() に反映されない)
        $this->get('http://evil.example.com/companies')
            ->assertStatus(403);
    }

    public function test_ループバック以外の接続元は403で弾く(): void
    {
        // Host: localhost を詐称されても、接続元アドレスがループバックでなければ通さない
        // （--host=0.0.0.0 束縛時に同一LANから叩かれるケース）。
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])
            ->get('http://localhost/companies')
            ->assertStatus(403);
    }

    public function test_ipv4射影のループバックは通過する(): void
    {
        // デュアルスタック束縛では接続元が ::ffff:127.0.0.1 で届くことがある（正当なローカルアクセス）
        $this->withServerVariables(['REMOTE_ADDR' => '::ffff:127.0.0.1'])
            ->get('http://localhost/companies')
            ->assertStatus(200);
    }

    public function test_ipv4射影でもループバック以外は403で弾く(): void
    {
        // 射影形式を前方一致で通すと ::ffff:192.168.1.1 まで抜けてしまうため、正規化して判定する
        $this->withServerVariables(['REMOTE_ADDR' => '::ffff:192.168.1.1'])
            ->get('http://localhost/companies')
            ->assertStatus(403);
    }

    public function test_セキュリティヘッダが付与される(): void
    {
        $this->get('/companies')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'same-origin');
    }

    /**
     * Host ヘッダのポートと実際の待ち受けポートが食い違う状況を作る。
     *
     * $this->get('http://localhost:31337/...') では再現できない。Symfony の Request::create が
     * URI のポートで SERVER_PORT まで上書きしてしまい、両者が必ず一致するため
     * （withServerVariables で渡した SERVER_PORT も URI 側に負ける）。
     * 実際の攻撃は「SERVER_PORT は本物の待ち受けポート・Host だけ攻撃者が指定」なので、
     * リクエストを組み立ててミドルウェアを直接通す。
     */
    private function requestWithHostPort(string $hostHeader, int $listeningPort): Request
    {
        $request = Request::create("http://{$hostHeader}/companies");
        $request->server->set('SERVER_PORT', $listeningPort);

        return $request;
    }

    public function test_待ち受けポートと違うhostヘッダは403で弾く(): void
    {
        // getHost() はポートを落とすため、ホスト名の照合だけでは Host: localhost:31337 が通る。
        // route() はこの Host からポート込みの絶対URLを作るので、素通りさせると
        // Gmail 連携の redirect_uri を攻撃者の待ち受けポートへ向けられる。
        try {
            (new EnsureLocalHost)->handle(
                $this->requestWithHostPort('localhost:31337', 8123),
                fn () => new Response('ok'),
            );

            $this->fail('待ち受けポートと違う Host が通過してしまった');
        } catch (HttpException $e) {
            // HttpException の HTTP ステータスは getCode() ではなく getStatusCode() に入る
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_待ち受けポートと同じhostヘッダは通過する(): void
    {
        $response = (new EnsureLocalHost)->handle(
            $this->requestWithHostPort('localhost:8123', 8123),
            fn () => new Response('ok'),
        );

        $this->assertSame('ok', $response->getContent());
    }

    public function test_403応答にもセキュリティヘッダが付く(): void
    {
        // SecurityHeaders は EnsureLocalHost より外側に置いてあるため、
        // 内側の abort(403) が応答へ変換されたあとでもヘッダが付く
        $this->get('http://evil.example.com/companies')
            ->assertStatus(403)
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_api応答にもセキュリティヘッダが付く(): void
    {
        // web グループ限定だと /api/* にヘッダが付かなかった（JSON でも nosniff は要る）
        $this->getJson('/api/companies')
            ->assertStatus(401)
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
