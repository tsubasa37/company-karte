<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ページコンポーネントの探索先
    |--------------------------------------------------------------------------
    |
    | このアプリのページは resources/js/Pages（大文字P）に置く。フロント側の解決も
    | resources/js/app.ts の `./Pages/${name}.vue` で大文字を前提にしている。
    |
    | パッケージ既定は resource_path('js/pages')（小文字）で、macOS は大文字小文字を
    | 区別しないため一致してしまうが、Linux では見つからない。テストの
    | `->component('Dashboard')` が CI でだけ「does not exist」で落ちる原因になるため、
    | 実際の配置に合わせて明示する。
    |
    | 注: Laravel の設定マージは最上位キー単位のため、'pages' を上書きする場合は
    | extensions も併せて書かないとパッケージ既定ごと消える。
    |
    */

    'pages' => [

        'ensure_pages_exist' => false,

        'paths' => [

            resource_path('js/Pages'),

        ],

        'extensions' => [

            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',

        ],

    ],

];
