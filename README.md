# company-karte（企業カルテ）

求人票・口コミ・公的データを突き合わせて、企業ごとの「カルテ」を作るローカルの Web アプリです。
求人票に書かれた主張を、口コミや公的データと照らし合わせて **整合 / 矛盾 / 検証不能** を根拠つきで示し、確かめきれない点は面接で聞く質問にまとめます。
結論は AI に断定させず、判断は使う人が面接で確かめて行う前提で作っています。

> 1 人で使うローカル専用のアプリです（ログイン機能はありません）。インターネットに公開するサーバーには置かないでください。

## 主な機能

- **材料の取り込み**: 求人票・口コミ・ニュース・面接メモを、貼り付け / URL / Chrome 拡張（ボタンを押したときだけ送信）で登録
- **AI 分析**: 主張の抽出 → 裏取り（整合・矛盾・検証不能）→ 求人票の言い回しの確認 → 口コミの整理 → 希望条件との照合
- **面接の準備**: 逆質問・面接前ブリーフィングの生成、確かめる論点の追跡（面接メモから解消・未解消を照合）
- **比較と判断**: おすすめ度と実質時給（どちらも PHP で計算）、複数社の比較、オファー額の差額
- **公的データ**: EDINET・gBizINFO からの自動取得、厚生労働省の公表事案との照合
- **予定と連絡**: 面接の予定表（.ics 書き出し）、Gmail からの面接日程の取り込み（任意）
- **運用**: ダッシュボード、取り込んだ材料の横断検索、カルテの Markdown 書き出し、分析前の自動バックアップ、AI の使用量と推定費用の表示

## 技術スタック

- Laravel 13（PHP 8.4）/ Inertia.js / Vue 3 + TypeScript / Tailwind CSS v4（Vite）
- SQLite、キューは database ドライバ
- AI の実行先を設定画面で切り替え: codex CLI（ChatGPT のプラン）/ OpenAI / Gemini / Anthropic の API
- Chrome 拡張（Manifest V3）
- PHPUnit（1,100 件以上）/ Laravel Pint / GitHub Actions（テスト・整形チェック・ビルド）

## 工夫した点

- **AI に結論を出させない**: 数値・照合・計算は PHP で確定し、AI には解釈・判定・要約だけを任せる。おすすめ度（`RecommendationEvaluator`）と実質時給（`SalaryBreakdownCalculator`）は PHP で計算する
- **AI の出力を信用しすぎない**: 引用が原文に本当にあるかを PHP で照合し（`QuoteVerifier`）、無い主張は除外する。応答はスキーマと大きさの上限で検証する（`OutputValidator`）
- **AI の実行先を 1 つの窓口にまとめる**: `AiRunner` ＋ 4 社分のドライバ。失敗しても別の実行先へ勝手に切り替えず、エラーとして記録する（費用の出どころとデータの送り先が黙って変わらないように）
- **プロンプトインジェクション対策**: 求人票・口コミ・メールなど外部の文章は、境界で囲んでから AI に渡す
- **ローカルサーバーの守り**: Host ヘッダの検証（DNS リバインディング対策）、URL 取得時の内部アドレスの遮断（SSRF 対策）、拡張からの送信はトークン認証
- **テストは外部に依存しない**: テスト中は本物の HTTP 通信とサブプロセスの起動を禁止している（`tests/TestCase.php`）

## 動かし方

必要なもの: PHP 8.4 / Composer / Node.js 22（nvm）/ SQLite

```bash
git clone <このリポジトリの URL>
cd company-karte
nvm use              # .nvmrc で Node 22 を指定
composer run setup   # 依存のインストール・.env 作成・キー生成・migrate・ビルド
composer run dev     # 画面・分析ワーカー・Vite を起動 → http://localhost:8123
```

AI 分析を使うには、次のどちらかを各自で用意します。

- **codex**: `npm i -g @openai/codex` で入れ、`codex login` で ChatGPT にログインする（認証情報は codex 自身が持ち、このアプリは預からない）
- **API**: `.env` に自分のキーを書く（`OPENAI_API_KEY` / `GEMINI_API_KEY` / `ANTHROPIC_API_KEY`）。モデルは設定画面の「AI エンジン」で選ぶ。費用は各自のアカウントに請求される

そのほか（任意）:

- Chrome 拡張の入れ方: [`extension/README.md`](extension/README.md)
- Gmail 取り込み・EDINET・gBizINFO の設定: `.env.example` の該当セクション

テスト:

```bash
php artisan test          # PHPUnit
vendor/bin/pint --test    # コードスタイル
npm run build             # フロントのビルド確認
```

## データの扱い

- カルテはローカルの SQLite（`database/database.sqlite`）にだけ保存します。
- AI 分析を実行すると、分析の材料（求人票・口コミ・面接メモ・公的データなど）が設定画面で選んだ実行先に送られます。**現年収・オファー額は送りません**（PHP 側の比較計算と画面表示にだけ使います）。
- 各社の学習・保持の扱い:

| 実行先 | 学習への利用 | 備考 |
|---|---|---|
| codex（ChatGPT のプラン） | ChatGPT 側の設定に従う | codex 自身が `~/.codex/sessions/` にプロンプトを保存する |
| OpenAI API | 使わない | `store:false` で送信し、OpenAI 側に保存させない |
| Gemini API | 無料枠のキーは使われる | 課金を有効にしたプロジェクトのキーを使う |
| Anthropic API | 使わない | 一部のモデルは 30 日保持 |

- 判定エンジン（TypeSafe AI の Jev）は実験的な機能で、既定はオフです。オンにすると、判定に必要な材料だけが米国の API に送られます。
- 口コミは自動巡回で集めません。自分のブラウザで表示したページを、自分の操作で取り込みます。各サイトの利用規約・著作権の範囲で使い、取り込んだ本文やカルテは公開・共有しないでください。

## ライセンス

MIT（[LICENSE](LICENSE)）
