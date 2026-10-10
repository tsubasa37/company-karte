<?php

namespace App\Services;

use App\Exceptions\FetchSizeExceededException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\MessageInterface;

/**
 * SSRF 対策込みで外部URLの本文（生バイト列）を取り出す唯一の経路。
 *
 * PublicUrlGuard が「公開だと確認済み」と返した IP に CURLOPT_RESOLVE で接続先を固定する。
 * ホスト名のまま接続すると実接続で名前解決がもう一度走り、2回目だけ内部IPを返す
 * DNS リバインディングで検証をすり抜けられるため。
 * IP を URL に埋める方式を採らないのは、SNI と証明書の検証を元のホスト名のまま保つため。
 * リダイレクトも Guzzle に追わせず 1ホップずつ「検証 → 固定 → 取得」を回す
 * （追従中のホップは curl 側で解決されてしまい、固定が効かないため）。
 *
 * 取得口が複数あると片方だけ固定を忘れる（実際に公表事案PDFの取得がホスト名のままだった）ので、
 * 外部への取得はテキスト用・PDF用ともにこのクラスへ集約する。
 *
 * 受信サイズの上限は2段で見る。長さを申告する相手にはヘッダ受信の時点で打ち切り（on_headers）、
 * 申告しない相手には受信後に判定する。
 *
 * 残存リスク（正直に記す）:
 * - CURLOPT_RESOLVE を効かせるため 'stream' は使えない（stream 指定だと Guzzle は
 *   StreamHandler に振り分け、curl のオプションが一切効かなくなる）。よって Content-Length を
 *   申告しない（chunked の）相手に対しては、転送中に打ち切れず受信後の判定になる。
 *   転送自体は php://temp にバッファされ、総取得時間の締め切りが実質の歯止めになる。
 * - 固定できるのは接続先IPまでで、その公開IPの先で内部へ中継するプロキシは防げない。
 */
class GuardedHttpFetcher
{
    /** 受信サイズの上限。超えたら切り詰めずに例外にする */
    private const MAX_BYTES = 10 * 1024 * 1024;

    private const MAX_REDIRECTS = 5;

    public function __construct(private readonly PublicUrlGuard $guard) {}

    /**
     * 検証済みIPへ固定して取得した本文をそのまま返す（HTML・PDF どちらも生のまま）。
     *
     * @param  int  $timeoutSeconds  リダイレクトを含めた総取得時間の上限。
     *                               1ホップごとの上限では合計が伸びてしまうため呼び出し側で決める
     *
     * @throws \RuntimeException
     */
    public function fetchBody(string $url, int $timeoutSeconds): string
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $response = $this->request($current, $deadline, $timeoutSeconds);

            if ($response->redirect()) {
                $current = $this->resolveLocation($current, $response->header('Location'));

                continue;
            }

            if (! $response->successful()) {
                throw new \RuntimeException("URL取得に失敗しました（HTTP {$response->status()}）: {$current}");
            }

            return $this->readWithinLimit($response, $current, $deadline, $timeoutSeconds);
        }

        throw new \RuntimeException('リダイレクトが'.self::MAX_REDIRECTS."回を超えたため中止しました: {$url}");
    }

    /** 1ホップ分の取得。検証済みIPへ接続を固定し、リダイレクトは追わせない */
    private function request(string $url, float $deadline, int $timeoutSeconds): Response
    {
        $ips = $this->guard->assertFetchable($url);

        try {
            return Http::timeout($this->remainingSeconds($deadline, $timeoutSeconds, $url))
                ->withHeaders(['User-Agent' => 'company-karte/1.0 (personal research)'])
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => $this->resolveOptions($url, $ips),
                    'on_headers' => $this->assertDeclaredLength($url),
                ])
                ->get($url);
        } catch (\Throwable $e) {
            // on_headers で打ち切ると Guzzle が包み、Laravel がさらに包んで投げ直す。
            // 中に自分が入れた理由があればそれを出す（「接続に失敗しました」に化けさせない）
            throw $this->sizeAbortIn($e) ?? $e;
        }
    }

    /** 例外の連なりの中から、自分が打ち切った理由を探す（無ければ null） */
    private function sizeAbortIn(\Throwable $e): ?FetchSizeExceededException
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof FetchSizeExceededException) {
                return $cause;
            }
        }

        return null;
    }

    /**
     * 申告された本文長が上限を超えていれば、本文を受け取る前に打ち切る。
     * 受信後の判定だけだと、上限を超える分まで一時領域に溜めてから捨てることになる。
     */
    private function assertDeclaredLength(string $url): \Closure
    {
        return function (MessageInterface $response) use ($url): void {
            $declared = $response->getHeaderLine('Content-Length');

            if (ctype_digit($declared) && (int) $declared > self::MAX_BYTES) {
                throw new FetchSizeExceededException(
                    $this->oversizeMessage($url)."（申告された長さ: {$declared}バイト）"
                );
            }
        };
    }

    /** 上限超過で中止したときの文言（申告で止めた場合も受信後に止めた場合も同じ理由） */
    private function oversizeMessage(string $url): string
    {
        $limitMb = self::MAX_BYTES / 1024 / 1024;

        return "取得サイズが上限（{$limitMb}MB）を超えたため中止しました: {$url}";
    }

    /**
     * 検証済みIPへ接続を固定する curl オプション。
     * ホストがIPリテラルなら名前解決自体が起きないので固定は不要。
     *
     * @param  array<int, string>  $ips
     * @return array<int, mixed>
     */
    private function resolveOptions(string $url, array $ips): array
    {
        $uri = new Uri($url);
        $host = $uri->getHost();

        // IPv6 リテラルは [::1] の形で入るのでブラケットを外してから判定する
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
        // CURLOPT_RESOLVE は host:port:ip1,ip2 形式。IPv6 はブラケットで囲む必要がある
        $addresses = implode(',', array_map(
            fn (string $ip): string => str_contains($ip, ':') ? "[{$ip}]" : $ip,
            $ips,
        ));

        return [CURLOPT_RESOLVE => ["{$host}:{$port}:{$addresses}"]];
    }

    /** Location ヘッダを絶対URLに直す（相対 Location もあるため） */
    private function resolveLocation(string $currentUrl, string $location): string
    {
        if (trim($location) === '') {
            throw new \RuntimeException("リダイレクト応答に Location がありません: {$currentUrl}");
        }

        try {
            $target = UriResolver::resolve(new Uri($currentUrl), new Uri(trim($location)));
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException("リダイレクト先URLを解釈できません（{$location}）: {$currentUrl}", 0, $e);
        }

        return (string) $target;
    }

    /** 残り時間。使い切っていたら例外（遅いサーバに無制限に付き合わないため） */
    private function remainingSeconds(float $deadline, int $timeoutSeconds, string $url): float
    {
        $remaining = $deadline - microtime(true);

        if ($remaining <= 0) {
            throw new \RuntimeException("取得が制限時間（{$timeoutSeconds}秒）を超えたため中止しました: {$url}");
        }

        return $remaining;
    }

    /**
     * 上限まで本文を読む。超過は切り詰めずに例外（欠けた本文を分析に渡さないため）。
     * 途中で切れた受信も「正常に読み終わった」ことにせず例外にする。
     */
    private function readWithinLimit(Response $response, string $url, float $deadline, int $timeoutSeconds): string
    {
        $psr = $response->toPsrResponse();
        $stream = $psr->getBody();
        $body = '';

        while (true) {
            $this->remainingSeconds($deadline, $timeoutSeconds, $url);

            $chunk = $stream->read(8192);

            if ($chunk === '') {
                if ($stream->eof()) {
                    break;
                }

                // 読めていないのに EOF でもない = 本文が途中で止まっている。
                // 「読み終わった」ことにすると欠けた本文がカルテに残り codex にも渡るため例外にする
                $received = strlen($body);

                throw new \RuntimeException("応答が途中で切れました（{$received}バイト受信）: {$url}");
            }

            $body .= $chunk;

            if (strlen($body) > self::MAX_BYTES) {
                throw new FetchSizeExceededException($this->oversizeMessage($url));
            }
        }

        $this->assertComplete($psr, strlen($body), $url);

        return $body;
    }

    /** Content-Length が分かる場合は受信バイト数と突き合わせる（途中で切れた本文を保存しないため） */
    private function assertComplete(MessageInterface $psr, int $received, string $url): void
    {
        $encoding = mb_strtolower(trim($psr->getHeaderLine('Content-Encoding')));

        if ($encoding !== '' && $encoding !== 'identity') {
            // gzip 等は curl が復号するため、Content-Length（圧縮後の長さ）とは元々一致しない
            return;
        }

        $declaredLength = $psr->getHeaderLine('Content-Length');

        if ($declaredLength === '' || ! ctype_digit($declaredLength)) {
            // chunked 等で長さが分からない場合は突き合わせようがない（curl 側が転送異常を例外にする）
            return;
        }

        if ((int) $declaredLength !== $received) {
            throw new \RuntimeException(
                "応答が途中で切れました（{$declaredLength}バイトのはずが{$received}バイト）: {$url}",
            );
        }
    }
}
