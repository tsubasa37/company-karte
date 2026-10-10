#!/usr/bin/env bash
# company-karte ローカル開発の一括起動
#   画面(http://localhost:8123) + 分析ワーカー(queue) + Vite を並列起動する。
#   使い方:  ./bin/dev.sh   または   composer run dev
#   停止:    Ctrl+C （-k で全プロセスまとめて停止）
set -euo pipefail

# スクリプトの位置からプロジェクトルートへ移動
cd "$(dirname "$0")/.."

# node は nvm の v22 を使う（既定 node が古いと Vite が動かない）
export NVM_DIR="${NVM_DIR:-$HOME/.nvm}"
if [ -s "$NVM_DIR/nvm.sh" ]; then
    # shellcheck disable=SC1091
    . "$NVM_DIR/nvm.sh"
    nvm use 22 >/dev/null
fi

# .env を変更している場合に備えて設定キャッシュをクリア
php artisan config:clear >/dev/null 2>&1 || true

echo "▶ 画面: http://localhost:8123   （Ctrl+C で全停止）"

# serve(8123) / queue(分析ワーカー) / vite を並列起動。どれか落ちたら全部止める(-k)。
#
# queue:work を使う（queue:listen ではない）。listen は --timeout を子ワーカーに渡さず
# 外側の Symfony Process のタイムアウトとしてのみ使うため、長い分析が閾値に達すると
# listen 自体が例外で落ち、-k により serve/vite も巻き添えで停止する。さらに強制終了された
# ジョブは failed() を通らないため analysis_runs が Running のまま固着する。
# work なら --timeout がワーカー本体に効き、ジョブだけが失敗として確定する。
# 代わりに PHP コードの変更はワーカーに自動反映されないので、ジョブ側を編集したら再起動すること。
#
# serve は artisan serve ではなく PHP ビルトインサーバを直接起動する。artisan serve は
# PHP_CLI_SERVER_WORKERS を子プロセスに渡さず同時1リクエストの完全直列になるため、
# ワーカー5本を明示してクリップの codex 同期実行中に画面全体が待たされるのを防ぐ。
# server.php は getcwd() をドキュメントルート(public)として使うため cd public が必須。
# schedule は Gmail のメール取り込み（15分おき）を回す。dev.sh を閉じている間は動かないが、
# 取得クエリの newer_than 窓により次回起動時にまとめて拾えるので取りこぼしにはならない。
npx concurrently -k \
    -c "#93c5fd,#fb7185,#fdba74,#86efac" \
    --names "serve,queue,vite,schedule" \
    "sh -c 'cd public && PHP_CLI_SERVER_WORKERS=5 exec php -S 127.0.0.1:8123 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'" \
    "php artisan queue:work --tries=1 --timeout=1500" \
    "npm run dev" \
    "php artisan schedule:work"
