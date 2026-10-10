<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | ローカル専用ツールのため、ブラウザからの cross-origin なAPI呼び出しは
    | 一切許可しない（paths を空にすると CORS ヘッダを付与しない＝任意サイトの
    | JS からの /api/clip 等への preflight を成立させない）。
    | ブラウザ拡張は manifest の host_permissions により CORS 制約を受けないため、
    | ここを閉じてもクリップ投入は動作する。
    |
    */

    'paths' => [],

    'allowed_methods' => ['*'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
