// 企業カルテ クリップ — 送信の一本化（service worker）。
// popup / content script からのメッセージを受け、ローカルのアプリへ送信する。
// トークンは config.js（Git 管理外）にのみ置き、各画面スクリプトには持たせない。

let configError = null;
try {
    importScripts('config.js');
} catch (e) {
    // 読み込み失敗の理由を捨てない。config.js が存在しても構文エラーならここに来るため、
    // 「ありません」だけだと在るファイルを探し続けることになる（サイレント失敗の禁止）。
    configError =
        'config.js を読み込めませんでした（詳細: ' +
        e.message +
        '）。extension/config.example.js を config.js にコピーし、TOKEN に .env の CLIP_TOKEN を設定してください。';
}

// 送信先がローカルのアプリであることをコード側でも強制する。
// config.js は手で書き換えられるため、1行の書き換えでカルテの本文（求人票・口コミ・
// 閲覧中ページの全文）が外部サーバーへ流れる経路にならないようにする。
// host_permissions で絞られていても、受け側が CORS を許可すれば本文は届くのでこれだけでは足りない。
if (!configError) {
    try {
        const endpoint = new URL(CLIP_CONFIG.ENDPOINT);
        const isLoopback = endpoint.hostname === '127.0.0.1' || endpoint.hostname === 'localhost' || endpoint.hostname === '[::1]';

        if (endpoint.protocol !== 'http:' || !isLoopback) {
            configError = '送信先がローカル（127.0.0.1 / localhost）ではありません: ' + CLIP_CONFIG.ENDPOINT;
        } else if (!endpoint.pathname.endsWith('/clip')) {
            // 一覧登録・企業一覧の URL は ENDPOINT からの文字列操作で作るため、
            // 末尾が /clip でないと黙って別の URL を叩くことになる
            configError = 'ENDPOINT の末尾は /clip である必要があります: ' + CLIP_CONFIG.ENDPOINT;
        }
    } catch (e) {
        configError = 'ENDPOINT を URL として解釈できません（詳細: ' + e.message + '）: ' + CLIP_CONFIG.ENDPOINT;
    }
}

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    // 送信元がこの拡張自身であることを確認する（多層防御）。
    // 今の manifest には content_scripts も externally_connectable も無いため Web ページや
    // 他拡張からは届かないが、送信面が増えたときに無検証のままにならないようここで弾く。
    // ただし content script を将来足した場合 sender.id は自拡張のままなので、
    // その content script 側でもページ由来データの検証が別途必要（このチェックだけでは足りない）。
    // 応答は返さず return false（不明な送信元にエラー文＝内部設定のヒントを渡さないため。
    // kind 不一致と同じ「このリスナーは扱わない」扱いに揃える）。
    if (!sender || sender.id !== chrome.runtime.id) {
        return false;
    }

    if (!message || (message.kind !== 'clip' && message.kind !== 'clip-list' && message.kind !== 'companies')) {
        return false;
    }

    // 企業一覧の取得は保存ではないので、送信状態の記録（lastClip）には載せず GET で取る
    if (message.kind === 'companies') {
        (async () => {
            if (configError) {
                sendResponse({ ok: false, message: configError });
                return;
            }

            try {
                const res = await fetch(CLIP_CONFIG.ENDPOINT.replace(/\/clip$/, '/companies'), {
                    headers: { Accept: 'application/json', 'X-Clip-Token': CLIP_CONFIG.TOKEN },
                });

                if (res.status === 401) {
                    sendResponse({
                        ok: false,
                        message: 'トークンが一致しません（.env の CLIP_TOKEN と extension/config.js の TOKEN を揃えてください）',
                    });
                    return;
                }

                sendResponse(await res.json());
            } catch (e) {
                sendResponse({
                    ok: false,
                    message:
                        '企業一覧を取得できませんでした：' +
                        e.message +
                        '（アプリ bin/dev.sh または php artisan serve --port=8123 が起動しているか確認してください）',
                });
            }
        })();

        return true;
    }

    (async () => {
        if (configError) {
            sendResponse({ ok: false, message: configError });
            return;
        }

        // clip-list は「いいね一覧の一括登録」用エンドポイント（/api/clip-list）
        const endpoint = message.kind === 'clip-list' ? CLIP_CONFIG.ENDPOINT + '-list' : CLIP_CONFIG.ENDPOINT;

        // popup が閉じられると sendResponse は届かなくなる（保存は成功しているのに
        // 「追加しました」が出ず、失敗と誤解して再送されがち）。そこで送信状態を
        // storage.session にも残し、popup が再度開いたときに復元表示できるようにする。
        const url = message.payload && message.payload.url;

        await chrome.storage.session.set({
            lastClip: { status: 'sending', kind: message.kind, url, startedAt: Date.now() },
        });

        try {
            // AbortController はあえて付けない。popup が閉じて受け取り先が消えても
            // fetch を中断せず保存を最後まで完走させるため（結果は lastClip から復元）。
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Clip-Token': CLIP_CONFIG.TOKEN,
                },
                body: JSON.stringify(message.payload),
            });

            let data;
            if (res.status === 401) {
                data = {
                    ok: false,
                    message: 'トークンが一致しません（.env の CLIP_TOKEN と extension/config.js の TOKEN を揃えてください）',
                };
            } else if (res.status === 429) {
                data = {
                    ok: false,
                    message: '送信が多すぎます（1分あたりの上限）。少し待つと再送できます',
                };
            } else {
                data = await res.json();
            }

            await chrome.storage.session.set({
                lastClip: { status: 'done', kind: message.kind, url, data, finishedAt: Date.now() },
            });
            sendResponse(data);
        } catch (e) {
            const errorMessage =
                '送信に失敗しました：' +
                e.message +
                '（アプリ bin/dev.sh または php artisan serve --port=8123 が起動しているか確認してください）';

            await chrome.storage.session.set({
                lastClip: { status: 'error', kind: message.kind, url, message: errorMessage },
            });
            sendResponse({ ok: false, message: errorMessage });
        }
    })();

    return true; // sendResponse を非同期で呼ぶため
});
