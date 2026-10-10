// 企業カルテ クリップ — 共通処理。
// popup.html から読み込むほか、描画待ちのため対象ページにも注入される。
// 同じタブに複数回注入されても壊れないよう、const/let の宣言ではなく
// globalThis への代入で定義する（再実行しても再宣言エラーにならない）。

// アプリ側の SourceType に対応する表示名
globalThis.CK_TYPE_LABEL = {
    jobposting: '求人票',
    review: '口コミ',
    shokuba: 'しょくばらぼ',
    edinet: 'EDINET',
    news: 'ニュース',
    other: 'その他',
};

globalThis.ckTypeLabel = function (type) {
    return globalThis.CK_TYPE_LABEL[type] || type;
};

// SPA・遅延描画のページは開いた直後だと本文が空なので、
// 表示テキストが安定する（2回連続で同じになる）まで最大8秒待つ。
globalThis.ckStableText = async function (minLength = 300) {
    const grab = () => (document.body ? document.body.innerText : '');
    let prev = null;

    for (let i = 0; i < 16; i++) {
        const text = grab();
        if (text.trim().length >= minLength && text === prev) {
            return text;
        }
        prev = text;
        await new Promise((r) => setTimeout(r, 500));
    }

    return grab();
};
