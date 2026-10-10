<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    // AI分析エンジン: codex CLI をサブプロセス実行（ChatGPTサブスク認証で走るためAPI課金¥0）
    'codex' => [
        // 未設定なら CodexBinaryLocator が nvm 配下等から探す。明示指定したいときだけ書く
        'bin' => env('CODEX_BIN'),
        'timeout' => (int) env('CODEX_TIMEOUT', 180),
        // 使用モデルは ai_provider_configs の codex 行（設定画面）に持つ。ここには置かない
        // codex の設定・セッションログの置き場。サブスク利用枠の消費率をここから読む
        'home' => env('CODEX_HOME', env('HOME').'/.codex'),
    ],

    // ブラウザ拡張からのクリップ投入APIの共有トークン
    'clip' => [
        'token' => env('CLIP_TOKEN'),
    ],

    // gBizINFO REST API（経産省・無料）。認定・補助金・調達実績の取得に使う
    'gbizinfo' => [
        'token' => env('GBIZINFO_API_TOKEN'),
    ],

    // EDINET API v2（金融庁・無料）。有報の平均年収等の取得に使う
    'edinet' => [
        'key' => env('EDINET_API_KEY'),
    ],

    // メール取り込みの取得量。
    // OAuthクライアント・許可ドメイン・伏せ字は設定画面から入力し mail_settings に保存する
    // （秘密の値を暗号化して持て、保存した瞬間に効くため）。ここには置かない。
    'mail_ingest' => [
        // 取得対象の期間。Mac を閉じていた間の取りこぼしを次回実行でまとめて拾うための窓
        'window_days' => (int) env('MAIL_FETCH_WINDOW_DAYS', 7),
        // 1回の取得で「取り込みに積む」上限（AI 実行数の歯止め。実行先が API なら課金の歯止めでもある）
        'max_messages' => (int) env('MAIL_FETCH_MAX_MESSAGES', 20),
        // 一覧で遡る通数の上限。取り込み上限を超えて窓の中に溜まった分へ進めるよう、
        // 一覧はここまでページを辿る（AI を使うのは未取り込み分だけなので費用は増えない）
        'scan_messages' => (int) env('MAIL_FETCH_SCAN_MESSAGES', 500),
    ],

    // 厚労省「労働基準関係法令違反に係る公表事案」の掲載ページ（長時間労働削減に向けた取組）
    'labor' => [
        'index_url' => env('LABOR_INDEX_URL', 'https://www.mhlw.go.jp/kinkyu/151106.html'),
    ],

];
