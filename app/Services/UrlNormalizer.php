<?php

namespace App\Services;

/**
 * ソースの同一性（同じページか）を判定するための URL 正規化。
 *
 * 同じ求人票・同じ記事が追跡パラメータ違いで別レコードになると、上書きが働かず
 * 改訂差分（source_revisions）も積まれない。保存時・照合時の両方でここを通す。
 *
 * 落とすのは「一致リストに挙げた追跡パラメータだけ」（ホワイトリスト方式）。
 * 求人IDに使われる一般クエリ（?jk= ?id= ?jobId= 等）を落とすと、別の求人が1レコードに
 * 統合されて既存本文が失われるため、知っているものだけを落とす。
 */
class UrlNormalizer
{
    /** 追跡パラメータ（キー完全一致・大小文字は無視） */
    private const TRACKING_PARAMS = [
        'gclid', 'fbclid', 'msclkid', 'yclid', 'dclid', 'igshid', 'gbraid', 'wbraid',
        '_ga', '_gl', 'mc_cid', 'mc_eid',
        // Bing News RSS のリンクに付く固定の参照元タグ
        'ref',
    ];

    /** 追跡パラメータ（キー前方一致）。utm_source / utm_medium / utm_campaign … をまとめて落とす */
    private const TRACKING_PREFIXES = ['utm_'];

    /** Bing News のリダイレクタ（url= に実記事URL・tid は取得のたびに変わる） */
    private const BING_HOSTS = ['www.bing.com', 'bing.com'];

    private const BING_REDIRECT_PATH = '/news/apiclick.aspx';

    public function normalize(string $url): string
    {
        // リダイレクタを実URLへ展開してから、展開後のURLにも同じ規則を適用する（展開は1段だけ）
        return $this->clean($this->expandRedirect($url));
    }

    /**
     * Bing News の apiclick リンクを実記事URLへ展開する。それ以外はそのまま返す。
     * ホスト完全一致で判定する（evil.com/bing.com/news/apiclick のようなパス偽装で発動させない）。
     */
    private function expandRedirect(string $url): string
    {
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (! in_array($host, self::BING_HOSTS, true) || ! str_starts_with($path, self::BING_REDIRECT_PATH)) {
            return $url;
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $target = $query['url'] ?? null;

        if (! is_string($target)) {
            return $url;
        }

        // 展開先は http/https で、ホストを持つものだけ採用する
        // （二重エンコードや javascript: 等は復元・採用せず、元のURLのまま照合する）
        $targetScheme = mb_strtolower((string) parse_url($target, PHP_URL_SCHEME));
        $hasHost = is_string(parse_url($target, PHP_URL_HOST));

        return in_array($targetScheme, ['http', 'https'], true) && $hasHost ? $target : $url;
    }

    /** スキーム・ホストの小文字化 / 末尾スラッシュ / 追跡パラメータ / フラグメントを揃える */
    private function clean(string $url): string
    {
        $parts = parse_url($url);

        // ホストを持たない文字列は正規化の対象外（URLの妥当性検証は投入側のバリデーションで行う）
        if ($parts === false || ! isset($parts['host'])) {
            return $url;
        }

        $scheme = isset($parts['scheme']) ? mb_strtolower($parts['scheme']).'://' : '//';
        $userInfo = isset($parts['user'])
            ? $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@'
            : '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $query = $this->filterQuery($parts['query'] ?? '');
        $fragment = $this->keepFragment($parts['fragment'] ?? null);

        return $scheme.$userInfo.mb_strtolower($parts['host']).$port
            .$this->normalizePath($parts['path'] ?? '')
            .($query !== '' ? '?'.$query : '')
            .($fragment !== null ? '#'.$fragment : '');
    }

    /** 末尾スラッシュの有無で別ページ扱いにしない（ルートだけは "/" を残す） */
    private function normalizePath(string $path): string
    {
        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }

    /**
     * クエリ文字列から追跡パラメータだけを落とす。
     * parse_str はキーの記号を書き換え・重複キーを落としてしまうため、自前で分解して
     * 元の並び順とエンコードをそのまま保つ。
     */
    private function filterQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $kept = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            if (! $this->isTracking(mb_strtolower(explode('=', $pair, 2)[0]))) {
                $kept[] = $pair;
            }
        }

        return implode('&', $kept);
    }

    private function isTracking(string $key): bool
    {
        if (in_array($key, self::TRACKING_PARAMS, true)) {
            return true;
        }

        foreach (self::TRACKING_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ページ内リンクのフラグメント（#section）は同じページなので落とす。
     * ハッシュルーティング（#/jobs/123 や #!/x）は別ページを指すため残す
     * （落とすと別の求人が1レコードに統合され、既存本文が失われる）。
     */
    private function keepFragment(?string $fragment): ?string
    {
        if ($fragment === null || $fragment === '') {
            return null;
        }

        return str_starts_with($fragment, '/') || str_starts_with($fragment, '!') ? $fragment : null;
    }
}
