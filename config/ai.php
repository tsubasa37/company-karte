<?php

// AI 実行先（OpenAI / Gemini / Anthropic の API）の接続先と単価。
// API キー・モデル・固有オプションは DB（ai_provider_configs）に置き、ここには置かない。
// Tier ごとの実行先の選択は ai_settings。codex はサブプロセス実行なので services.codex（bin / timeout / home）側。
return [

    'providers' => [
        // timeout は1回の呼び出しの上限秒。既定 180 は他ジョブ（逆質問・ブリーフィング 600 秒、
        // 口コミ分割・条件抽出・メール取り込み 300 秒）と拡張の送信 TTL（200 秒）が CODEX_TIMEOUT=180 を
        // 前提にした固定値のため。180 を超える値にするならそれらの見直しが要る
        // 出力上限（openai: max_output_tokens / gemini: maxOutputTokens / anthropic: max_tokens）は
        // 3社そろえて既定 32000。鍵名は各社の API パラメータ名に合わせてある。
        // 上限を送らないと、長い生成が timeout に当たって usage を取れないまま切れる
        // （課金されたのに費用が出ない実行になる）。上限に当たった場合は各社とも usage 付きで返るので、
        // AiRequestFailed として「課金済みの失敗」に記録できる。
        // 32000 の根拠: 各社のモデル上限（OpenAI GPT-5.6 系 128,000 / Gemini 3 系 65,536 /
        // Anthropic 64k〜128k）より十分小さく、どのモデルでも通る値。上げるときは timeout も併せて見直す
        'openai' => [
            // API キーは .env に置く（DB には持たない）。未設定なら実行先の候補に出ない
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'timeout' => (int) env('OPENAI_TIMEOUT', 180),
            // 推論トークンもこの上限に含まれる。超えると status=incomplete
            // （incomplete_details.reason=max_output_tokens）で usage 付きの応答が返る
            // （確認 2026-09-03: https://platform.openai.com/docs/api-reference/responses/create
            //  ・ モデル上限 128,000 は https://platform.openai.com/docs/models/gpt-5.6-sol）
            'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS', 32000),
        ],
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'timeout' => (int) env('GEMINI_TIMEOUT', 180),
            // 思考トークンもこの上限に含まれる。超えると finishReason=MAX_TOKENS で
            // usageMetadata 付きの応答が返る
            // （確認 2026-09-03: https://ai.google.dev/api/generate-content
            //  ・ モデル上限 65,536 は https://ai.google.dev/gemini-api/docs/models/gemini-3.5-flash）
            'max_output_tokens' => (int) env('GEMINI_MAX_OUTPUT_TOKENS', 32000),
        ],
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'timeout' => (int) env('ANTHROPIC_TIMEOUT', 180),
            // anthropic-version は必須ヘッダ。現行値は 2023-06-01 の1つだけ
            // （確認 2026-09-03: https://platform.claude.com/docs/en/api/versioning）
            'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
            // max_tokens は必須で、思考トークンもこの上限に含まれる（budget_tokens はこれ未満である必要がある）。
            // モデル別の上限は Haiku 4.5 / Sonnet 4.5 / Opus 4.5 が 64k、それ以降が 128k なので、
            // どのモデルでも通る値として 32000 を既定にする。timeout 180 秒に収まらない長さは
            // 接続失敗として落ちるため、上げるときは timeout も併せて見直す
            // （確認 2026-09-03: https://platform.claude.com/docs/en/api/messages）
            'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 32000),
        ],
        // 判定専用（AiKind::Decision。生成の Driver では使わない）。POST {base_url}/systemone
        // 1 回 0.2〜0.4 秒なので timeout は 10 秒。リトライはしない
        // （確認 2026-09-23: 公開情報をもとにした値。実際の応答との照合はまだ）
        'jev' => [
            'api_key' => env('TYPESAFE_API_KEY'),
            'base_url' => env('TYPESAFE_BASE_URL', 'https://api.typesafe.ai/v1'),
            'timeout' => (int) env('TYPESAFE_TIMEOUT', 10),
        ],
    ],

    // 単価表（USD / 1M tokens。標準ティアのみ）。各社の料金ページから転記し、転記日と URL をコメントで残す。
    // 未登録のモデルは cost_micro_usd = null（0 で埋めない）。費用は推定値で、請求の正は各社のダッシュボード。
    // 鍵は ai_provider_configs に登録するモデル名と完全一致で引く（別名・日付付き ID は別々に登録する）。
    // 形: 'model' => ['input' => .., 'cached_input' => .., 'cache_write' => ..（任意）, 'output' => ..]
    //     cache_write が無いモデルはキャッシュ書き込み分も input 単価のまま（書き込みに追加課金が無い社の扱い）
    //     入力量で段階が変わるモデルは 'bands' => [['up_to' => 200000, ...], ['up_to' => null, ...]]（入力総量 <= up_to の最初の帯）
    // codex は ChatGPT サブスクで走るため単価表を持たない（費用は常に null）
    'pricing' => [
        // 転記 2026-09-03: https://platform.openai.com/docs/pricing（Short context / Long context の表）
        // 長文帯の境目は「入力 272K トークン超はリクエスト全体が入力 2 倍・出力 1.5 倍」
        // （https://platform.openai.com/docs/models/gpt-5.6-sol）。gpt-5.6-sol は 2026-11-21 までのプロモ価格
        // cache_write は GPT-5.6 以降だけに存在する課金で「入力単価の 1.25 倍」。GPT-5.5 以前は
        // キャッシュ書き込みに追加課金が無いため、5.6 系にだけ cache_write を置く
        // （確認 2026-09-03: https://platform.openai.com/docs/guides/prompt-caching の
        //  "Summary of model differences" → Cache write charge）
        'openai' => [
            'gpt-5.6-sol' => ['bands' => [
                ['up_to' => 272000, 'input' => 4.00, 'cached_input' => 0.40, 'cache_write' => 5.00, 'output' => 20.00],
                ['up_to' => null, 'input' => 8.00, 'cached_input' => 0.80, 'cache_write' => 10.00, 'output' => 30.00],
            ]],
            'gpt-5.6-terra' => ['bands' => [
                ['up_to' => 272000, 'input' => 2.00, 'cached_input' => 0.20, 'cache_write' => 2.50, 'output' => 12.00],
                ['up_to' => null, 'input' => 4.00, 'cached_input' => 0.40, 'cache_write' => 5.00, 'output' => 18.00],
            ]],
            'gpt-5.6-luna' => ['bands' => [
                ['up_to' => 272000, 'input' => 0.20, 'cached_input' => 0.02, 'cache_write' => 0.25, 'output' => 1.20],
                ['up_to' => null, 'input' => 0.40, 'cached_input' => 0.04, 'cache_write' => 0.50, 'output' => 1.80],
            ]],
        ],
        // 転記 2026-09-03: https://ai.google.dev/gemini-api/docs/pricing（Standard・有料枠。思考トークンは出力単価）
        // gemini-3.8-flash は 2026-12-31 までの価格。2027-01-01 から input 1.50 / cached_input 0.15 / output 7.50 に上がる予告あり
        // キャッシュ書き込みのトークン単価は無い（明示キャッシュは保存時間で課金）ため cache_write は持たない
        'gemini' => [
            'gemini-3.1-pro-preview' => ['bands' => [
                ['up_to' => 200000, 'input' => 2.00, 'cached_input' => 0.20, 'output' => 12.00],
                ['up_to' => null, 'input' => 4.00, 'cached_input' => 0.40, 'output' => 18.00],
            ]],
            'gemini-3.8-flash' => ['input' => 0.75, 'cached_input' => 0.075, 'output' => 3.75],
            'gemini-3.5-flash' => ['input' => 1.50, 'cached_input' => 0.15, 'output' => 9.00],
            'gemini-3.5-flash-lite' => ['input' => 0.30, 'cached_input' => 0.03, 'output' => 2.50],
        ],
        // 転記 2026-09-03: https://docs.claude.com/en/docs/about-claude/pricing
        // cache_write は 5 分キャッシュの書き込み単価（1 時間キャッシュは入力の 2 倍で別単価。ドライバは既定の 5 分を使う前提）
        // 4.6 以降は 1M コンテキスト全体が同一単価なので帯なし。モデル ID は別名（Anthropic の公式ドキュメントの一覧）
        'anthropic' => [
            'claude-fable-5-1' => ['input' => 10.00, 'cached_input' => 0.25, 'cache_write' => 12.50, 'output' => 50.00],
            'claude-opus-5' => ['input' => 5.00, 'cached_input' => 0.50, 'cache_write' => 6.25, 'output' => 25.00],
            'claude-sonnet-5' => ['input' => 2.00, 'cached_input' => 0.20, 'cache_write' => 2.50, 'output' => 10.00],
            'claude-haiku-4-5' => ['input' => 1.00, 'cached_input' => 0.10, 'cache_write' => 1.25, 'output' => 5.00],
        ],
        // 転記 2026-09-22（公式 https://docs.typesafe.ai/ の料金）。出力は無料。
        // キャッシュの概念が無いので cached_input は input と同額（AiPricing が cached_input を必須にしているため）。
        // jev-latest は予告なく中身が変わるため載せない（バージョン固定で使う）
        'jev' => [
            'jev-1.13.0' => ['input' => 0.042, 'cached_input' => 0.042, 'output' => 0],
        ],
    ],

    // 判定エンジン（AiKind::Decision）の入力上限。送る前に PHP 側で検査する。
    // 3 つとも自前の安全弁（公式の上限はトークン予算 state + 全質問 64k / state + 最長の質問 32k のみで、
    // 質問数の上限は未公開）。255 は choice の候補上限として二次情報にあった値（確認 2026-09-23: 公開情報をもとにした値。実際の応答との照合はまだ）
    'decision' => [
        // 一括判定（decideMany）の同時数。レート制限 1,200 rpm・日本からの往復 0.3 秒を前提に 20
        // （確認 2026-09-23: 公開情報をもとにした値。実際の応答との照合はまだ）
        'concurrency' => 20,
        // 1 秒あたりの送信件数の上限（1,200 rpm = 20 件/秒）。区切りの間に間を空けてこれを守る
        'requests_per_second' => 20,
        'limits' => [
            'max_questions' => 60,
            'max_choice_options' => 255,
            'max_state_chars' => 20000,
        ],
        // 口コミの機械判定（RJ）で、確率から関係を確定し集計するしきい値（PHP が使う）。
        // 画面には出さない（確率の生数値を見せない方針）。実際の応答を見て見直す
        'thresholds' => [
            // 最大確率の候補がこれ以上なら、その候補で確定する（未満は unsure）
            'relation_min_probability' => 0.7,
            // confidence がこれ未満なら、確率が高くても unsure にする
            'relation_min_confidence' => 0.5,
            // LLM の判定と逆向きの口コミがこの件数以上あれば「要確認」を立てる
            'conflict_flag_min_reviews' => 3,
            // ニュースの機械判定。noul がこれ以上なら「関連あり」
            'news_relevant_min' => 0.5,
            // 深刻度の score（段階 3 つの 1 始まりの位置）がこれ以上なら「注意」「深刻」
            'news_severity_caution_min' => 1.5,
            'news_severity_serious_min' => 2.5,
            // 拡張のクリップ判定の前段。is_multi_company の noul がこれ以上なら一覧ページとして弾く
            'clip_multi_company_min' => 0.7,
            // is_company_related の noul がこれ未満なら企業に関係ないページとして弾く
            'clip_company_related_min' => 0.3,
            // 面接メモ → 論点の消し込み。最大確率と confidence がどちらも以上なら状態を確定する
            // （未達は not_mentioned として状態を変えない）
            'issue_status_min_probability' => 0.7,
            'issue_status_min_confidence' => 0.5,
            // 根拠の引用に採る文の noul の下限（未満なら引用なし）
            'issue_evidence_min' => 0.5,
        ],
    ],

    // 料金シミュレーション（php artisan ai:cost-estimate）で使う、文字数 → トークン数の換算率（トークン / 文字）。
    //
    // 実際の課金はトークン数で決まるが、見積もりの土台になるのは「過去の実行が何文字だったか」で、
    // まだ使っていない実行先のトークン数は分からない（トークン化は社・モデルごとに違い、
    // 旧 codex_usages にはトークンの列自体が無い）。そこで文字数から換算するが、
    // 率そのものが不確かなので1つの数字に丸めず低め・高めの2通りで見積もる。
    //
    // 根拠: 日本語主体のテキストで広く使われている目安（1文字 ≒ 0.7〜1.2 トークン）で、
    //   このプロジェクトでの実測値ではない。トークナイザ・言語比率・JSON スキーマの記号量で上下する。
    // 置き換え方: API（OpenAI / Gemini / Anthropic）で1回でも実行すると ai_usages にトークン数が残る。
    //   記録が1件でもあれば ai:cost-estimate はこの既定値を使わず、記録から実測した率に自動で切り替える。
    //   実測が十分貯まったら、その率をここの既定値にも反映する。
    // 円換算のレート（1 USD が何円か）。各社の請求は USD で行われるため、円は表示のための目安。
    // 転記 2026-09-02: USD/JPY 終値 158.93（https://tradingeconomics.com/japan/currency）。
    // レートは日々動くので、正確さが要るときは AI_ESTIMATE_JPY_PER_USD で上書きするか、この既定値を書き換える。
    'estimate' => [
        // 2026-09-03 に Gemini（gemini-3.5-flash-lite）で実測した値を中心に幅を取る。
        // 実測: 入力 0.53 / 出力 0.19 トークU/文字（接続テスト + 分析 P1・P4 の3回、計 7,525 字）。
        // 出力が低いのは、記録している文字数が JSON 文字列（日本語が \uXXXX に展開される）だから。
        // ai_usages にトークンの記録が1件でもあれば、この既定値ではなく実測から算出した率に切り替わる。
        'tokens_per_char_low' => 0.4,
        'tokens_per_char_high' => 0.7,
        'jpy_per_usd' => (float) env('AI_ESTIMATE_JPY_PER_USD', 158.93),
    ],

];
