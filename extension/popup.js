// 企業カルテ クリップ — ポップアップ。
// 開いた時点ではページ本文を読み取るだけで、送信はしない。
// どう追加するかはボタンで選ぶ（自動判定 / 求人票 / 口コミ / ニュース / 一覧一括登録）。
// トークンと送信処理は background.js / config.js 側にあり、このファイルは UI のみ。

const statusEl = document.getElementById('status');
const actionsEl = document.getElementById('actions');
const listBtn = document.getElementById('list-btn');
const companyEl = document.getElementById('company');
const companyNoteEl = document.getElementById('company-note');
const previewEl = document.getElementById('preview');
const previewHostEl = document.getElementById('preview-host');
const previewTextEl = document.getElementById('preview-text');
const previewMoreEl = document.getElementById('preview-more');

// 開いたときに取得したページ本文。送信はボタンを押すまで行わない
let page = null;

// 「送信処理中」の復元表示中はボタンを無効のままにするためのフラグ（二重送信防止）
let sendingLocked = false;

// popup を開いたまま TTL を迎えたときに警告表示へ切り替えるタイマー
let sendingTimer = null;

// service worker が落ちて done/error が書かれなかった sending をいつまでも信じない上限。
// サーバ側のAI実行は実行先ごとの上限（既定180秒）まで走るため、それより短くすると
// 処理中なのに「確認できませんでした」と出て再送を誘発する。上限＋余裕で200秒。
const SENDING_TTL_MS = 200 * 1000;

// 送信前に見せる本文の長さ。ページを見分けるには足り、popup が縦に伸びすぎない量。
const PREVIEW_CHARS = 120;

// XSS を避けるため innerHTML は使わず、textContent / createElement で組み立てる。
function setText(message, muted = false) {
    statusEl.className = muted ? 'muted' : '';
    statusEl.textContent = message;
    // 空文字なら行ごと消す（プレビューの上に空行を残さない）
    statusEl.hidden = message === '';
}

function setBusy(busy) {
    for (const button of actionsEl.querySelectorAll('button')) {
        button.disabled = busy || !page || sendingLocked;
    }
    companyEl.disabled = busy || sendingLocked;
}

// 登録済み企業を読み込んで選択肢にする。取得できない場合は理由を出す（黙って空にしない）。
async function loadCompanies() {
    let data;
    try {
        data = await chrome.runtime.sendMessage({ kind: 'companies' });
    } catch (e) {
        companyNoteEl.textContent = '⚠️ 企業一覧を取得できませんでした：' + e.message;
        return;
    }

    if (!data || !data.ok) {
        companyNoteEl.textContent = '⚠️ ' + ((data && data.message) || '企業一覧を取得できませんでした');
        return;
    }

    for (const company of data.companies) {
        const option = document.createElement('option');
        option.value = String(company.id);
        option.textContent = company.name;
        companyEl.append(option);
    }

    companyNoteEl.textContent = `登録済み ${data.companies.length}社。未指定ならAIが企業を判定します`;
}

// 企業と種別の両方を指定するとサーバ側はAI判定を行わない（AIを呼ばない＝速い）
function selectedCompanyId() {
    return companyEl.value === '' ? null : Number(companyEl.value);
}

function appLink(path, label) {
    const a = document.createElement('a');
    a.href = 'http://127.0.0.1:8123' + path;
    a.target = '_blank';
    a.textContent = label;

    return a;
}

// 何を送ろうとしているかを送信前に見せる。
//
// 押した瞬間にページ全文が AI の実行先（ChatGPT / 各社API）へ渡り、あとから回収できない。
// 求人サイトのつもりでログイン済みの社内システムやメールの画面で押す事故を、
// 押す前に本人が気づけるようにするための表示なので、開閉式にはしない
// （急いでいるときほど開かないため）。
//
// ページ由来の文字列なので innerHTML は使わず textContent で入れる。
function showPreview(captured) {
    let host;
    try {
        // file:// や about: は解釈に成功するが host が空文字になる。空欄にすると
        // 「どこへ送るのか」が消えてしまうので、その場合は URL をそのまま出す
        host = new URL(captured.url).host || captured.url;
    } catch (e) {
        host = captured.url;
    }

    previewHostEl.textContent = host;
    previewHostEl.title = captured.url;

    // 改行だらけのページでも3行に収まるよう空白を潰してから切る
    const collapsed = captured.text.replace(/\s+/g, ' ').trim();
    const total = captured.text.length.toLocaleString();

    previewTextEl.textContent = collapsed.slice(0, PREVIEW_CHARS);
    previewMoreEl.textContent =
        collapsed.length > PREVIEW_CHARS
            ? `本文 ${total}字 のうち先頭 ${PREVIEW_CHARS}字`
            : `本文 ${total}字（全文）`;

    previewEl.hidden = false;
}

function setSuccess(companyName, sourceType, companyId) {
    statusEl.className = '';
    statusEl.hidden = false;
    statusEl.replaceChildren();

    const l1 = document.createElement('div');
    l1.textContent = '✅ 追加しました';

    const l2 = document.createElement('div');
    const b = document.createElement('b');
    b.textContent = companyName;
    l2.append(b, document.createTextNode(`（${ckTypeLabel(sourceType)}）`));

    statusEl.append(l1, l2, appLink('/companies/' + encodeURIComponent(String(companyId)), 'カルテを開く'));
}

function setListResult(created, existing) {
    statusEl.className = '';
    statusEl.hidden = false;
    statusEl.replaceChildren();

    const l1 = document.createElement('div');
    l1.textContent = `✅ ${created.length}社を新規登録（登録済み ${existing.length}社はスキップ）`;
    statusEl.append(l1);

    if (created.length > 0) {
        const l2 = document.createElement('div');
        l2.className = 'muted';
        l2.textContent = created.join(' / ');
        statusEl.append(l2);
    }

    statusEl.append(appLink('/companies', '企業一覧を開く'));
}

// 「送信処理中」が TTL を超えた場合の表示。ボタンのロックを解除して再送できるようにする
// （サーバ側では保存に成功している可能性があるため、確認を促す文言にする）。
function showSendingTimeout() {
    sendingLocked = false;
    setText('⚠️ 送信結果が確認できませんでした（サーバ側では保存されている可能性があります。ソース一覧を確認してから再送してください）');
    setBusy(false);
}

// popup を閉じても background の送信は続くため、直前の送信状態を storage.session から
// 復元する（閉じると sendResponse が届かず、保存に成功しても「追加しました」が出ないため）。
// 表示するのは現在のページと URL が一致する記録だけ。一致しなければ何も出さない。
async function restoreLastClip() {
    const { lastClip } = await chrome.storage.session.get('lastClip');
    if (!lastClip || !page || lastClip.url !== page.url) {
        return;
    }

    if (lastClip.status === 'sending') {
        // service worker が完走すれば必ず done/error に置き換わる。TTL を超えて sending の
        // ままなら SW が途中で死んだとみなし、ロックせず再送できる状態で警告だけ出す。
        const elapsed = Date.now() - (lastClip.startedAt || 0);
        if (elapsed > SENDING_TTL_MS) {
            showSendingTimeout();
            return;
        }

        sendingLocked = true; // setBusy(false) が呼ばれてもボタンは無効のまま
        setText('⏳ このページは送信処理中です（閉じても継続します）', true);

        // popup を開いたまま TTL を迎えた場合も警告表示へ切り替える（完了すれば onChanged が先に解除する）
        clearTimeout(sendingTimer);
        sendingTimer = setTimeout(showSendingTimeout, SENDING_TTL_MS - elapsed);
        return;
    }

    if (lastClip.status === 'error') {
        setText('⚠️ ' + lastClip.message);
        return;
    }

    // status === 'done'：送信直後と同じ表示を再現する（ok:false は 401/429 等の応答）
    const data = lastClip.data;
    if (data && data.ok) {
        if (lastClip.kind === 'clip-list') {
            setListResult(data.created || [], data.existing || []);
        } else {
            setSuccess(data.company_name, data.source_type, data.company_id);
        }
        return;
    }

    setText('⚠️ ' + ((data && data.message) || '追加できませんでした'));
}

// popup を開いたまま background の送信が完了（done/error）したら、その場で結果表示に更新する。
// sending の書き込みは無視する（表示は restoreLastClip / sendClip が既に行っているため）。
chrome.storage.session.onChanged.addListener((changes) => {
    const next = changes.lastClip && changes.lastClip.newValue;
    if (!next || !page || next.url !== page.url || next.status === 'sending') {
        return;
    }

    clearTimeout(sendingTimer);
    sendingLocked = false;
    restoreLastClip().then(() => setBusy(false));
});

// 対象タブの表示テキストを取得する。描画待ちは common.js の ckStableText を
// ページ側に注入して使う（executeScript の func からは popup のスコープを参照できないため）。
async function capturePage() {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (!tab || !tab.id) {
        setText('⚠️ 対象タブが取得できませんでした');
        return null;
    }

    await chrome.scripting.executeScript({ target: { tabId: tab.id }, files: ['common.js'] });

    const [{ result }] = await chrome.scripting.executeScript({
        target: { tabId: tab.id },
        func: async () => ({
            title: document.title,
            url: location.href,
            text: await ckStableText(),
        }),
    });

    if (!result || !result.text || result.text.trim().length < 20) {
        setText('⚠️ このページから十分なテキストを取得できませんでした');
        return null;
    }

    return result;
}

// 種別を選んで（または自動判定で）このページを1件のソースとして追加する
async function sendClip(type) {
    if (!page) {
        return;
    }

    try {
        setBusy(true);

        const companyId = selectedCompanyId();
        const payload = { title: page.title, url: page.url, text: page.text };
        if (type) {
            payload.type = type;
        }
        if (companyId !== null) {
            payload.company_id = companyId;
        }

        // AI判定が要る場合はAIの実行を待つため長くかかる（最大3分ほど）
        if (companyId !== null && type) {
            setText(`${ckTypeLabel(type)}として保存中…（AI判定なし）`, true);
        } else if (type) {
            setText(`${ckTypeLabel(type)}として保存中…（AIが企業名を判定します。最大3分ほどかかります）`, true);
        } else {
            setText('AIが企業と種別を判定中…（最大3分ほどかかります）', true);
        }

        const data = await chrome.runtime.sendMessage({ kind: 'clip', payload });

        if (data && data.ok) {
            setSuccess(data.company_name, data.source_type, data.company_id);
            return;
        }

        setText('⚠️ ' + ((data && data.message) || '追加できませんでした'));

        // 一覧ページだった場合は、そのまま一括登録に進めるようボタンを強調する
        if (data && data.reason === 'list_page') {
            listBtn.className = 'primary';
            listBtn.textContent = '📋 このページの企業をまとめて登録する';
        }
    } catch (e) {
        setText('⚠️ 送信に失敗しました：' + e.message);
    } finally {
        setBusy(false);
    }
}

// 「いいね・お気に入り一覧」ページから企業だけを一括登録する
async function sendList() {
    if (!page) {
        return;
    }

    try {
        setBusy(true);
        setText('一覧から企業名を抽出中…（最大3分ほどかかります）', true);

        const data = await chrome.runtime.sendMessage({
            kind: 'clip-list',
            payload: { title: page.title, url: page.url, text: page.text },
        });

        if (data && data.ok) {
            setListResult(data.created || [], data.existing || []);
        } else {
            setText('⚠️ ' + ((data && data.message) || '登録できませんでした'));
        }
    } catch (e) {
        setText('⚠️ 送信に失敗しました：' + e.message);
    } finally {
        setBusy(false);
    }
}

for (const button of actionsEl.querySelectorAll('button[data-type]')) {
    button.addEventListener('click', () => sendClip(button.dataset.type));
}
listBtn.addEventListener('click', sendList);

// 起動時: ページ本文の取得と企業一覧の読み込みだけ行う（送信はしない）
(async () => {
    setBusy(true);
    loadCompanies();

    try {
        setText('ページを取得中…（描画完了を待っています）', true);
        page = await capturePage();
        if (!page) {
            return; // capturePage が理由を表示済み
        }

        // 文字数はプレビュー枠の中に出るので、案内文の行は畳む
        showPreview(page);
        setText('');

        // このページに前回の送信記録があれば状態を復元する（プレビューはそのまま残す）
        await restoreLastClip();
    } catch (e) {
        setText('⚠️ ページを取得できませんでした：' + e.message);
    } finally {
        setBusy(false);
    }
})();
