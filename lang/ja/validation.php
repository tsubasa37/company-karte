<?php

/**
 * 入力の拒否理由（日本語）。
 *
 * 画面の文言はすべて日本語に揃える方針のため、フレームワーク既定の英語メッセージは使わない。
 * :attributeには下の 'attributes' で定義した日本語の項目名が入る。
 * 項目名を登録し忘れると英語の列名がそのまま出るので、フォームを足したら 'attributes' も足すこと。
 */
return [

    'accepted' => ':attributeを承認してください。',
    'accepted_if' => ':otherが:valueの場合、:attributeを承認してください。',
    'active_url' => ':attributeは有効なURLではありません。',
    'after' => ':attributeには:dateより後の日付を指定してください。',
    'after_or_equal' => ':attributeには:date以降の日付を指定してください。',
    'alpha' => ':attributeはアルファベットのみで入力してください。',
    'alpha_dash' => ':attributeはアルファベット・数字・ハイフン・アンダースコアのみで入力してください。',
    'alpha_num' => ':attributeはアルファベットと数字のみで入力してください。',
    'any_of' => ':attributeの形式が正しくありません。',
    'array' => ':attributeは配列で指定してください。',
    'ascii' => ':attributeは半角の英数字と記号のみで入力してください。',
    'before' => ':attributeには:dateより前の日付を指定してください。',
    'before_or_equal' => ':attributeには:date以前の日付を指定してください。',
    'between' => [
        'array' => ':attributeは:min個から:max個までにしてください。',
        'file' => ':attributeは:min KBから:max KBまでのファイルにしてください。',
        'numeric' => ':attributeは:minから:maxまでの値にしてください。',
        'string' => ':attributeは:min文字から:max文字までで入力してください。',
    ],
    'boolean' => ':attributeには true か false を指定してください。',
    'can' => ':attributeに許可されていない値が含まれています。',
    'confirmed' => ':attributeの確認用の入力が一致しません。',
    'contains' => ':attributeに必要な値が含まれていません。',
    'current_password' => 'パスワードが正しくありません。',
    'date' => ':attributeは日付として正しくありません。',
    'date_equals' => ':attributeには:dateと同じ日付を指定してください。',
    'date_format' => ':attributeは:formatの形式で入力してください。',
    'decimal' => ':attributeは小数点以下:decimal桁で入力してください。',
    'declined' => ':attributeを拒否してください。',
    'declined_if' => ':otherが:valueの場合、:attributeを拒否してください。',
    'different' => ':attributeと:otherには異なる値を指定してください。',
    'digits' => ':attributeは:digits桁で入力してください。',
    'digits_between' => ':attributeは:min桁から:max桁までで入力してください。',
    'dimensions' => ':attributeの画像サイズが正しくありません。',
    'distinct' => ':attributeに重複した値があります。',
    'doesnt_contain' => ':attributeに使用できない値が含まれています。',
    'doesnt_end_with' => ':attributeの末尾に使用できない文字列があります。',
    'doesnt_start_with' => ':attributeの先頭に使用できない文字列があります。',
    'email' => ':attributeはメールアドレスの形式で入力してください。',
    'encoding' => ':attributeの文字コードが正しくありません。',
    'ends_with' => ':attributeは:valuesのいずれかで終わる必要があります。',
    'enum' => ':attributeに選択できない値が指定されています。',
    'exists' => ':attributeに選択された値は存在しません。',
    'extensions' => ':attributeの拡張子は:valuesのいずれかにしてください。',
    'file' => ':attributeはファイルを指定してください。',
    'filled' => ':attributeを入力してください。',
    'gt' => [
        'array' => ':attributeは:value個より多く指定してください。',
        'file' => ':attributeは:value KBより大きいファイルにしてください。',
        'numeric' => ':attributeは:valueより大きい値にしてください。',
        'string' => ':attributeは:value文字より多く入力してください。',
    ],
    'gte' => [
        'array' => ':attributeは:value個以上で指定してください。',
        'file' => ':attributeは:value KB以上のファイルにしてください。',
        'numeric' => ':attributeは:value以上の値にしてください。',
        'string' => ':attributeは:value文字以上で入力してください。',
    ],
    'hex_color' => ':attributeは色コード（16進数）の形式で入力してください。',
    'image' => ':attributeは画像ファイルを指定してください。',
    'in' => ':attributeに選択できない値が指定されています。',
    'in_array' => ':attributeの値が:otherに含まれていません。',
    'in_array_keys' => ':attributeに必要なキーが含まれていません。',
    'integer' => ':attributeは整数で入力してください。',
    'ip' => ':attributeはIPアドレスの形式で入力してください。',
    'ipv4' => ':attributeはIPv4アドレスの形式で入力してください。',
    'ipv6' => ':attributeはIPv6アドレスの形式で入力してください。',
    'json' => ':attributeはJSONの形式で入力してください。',
    'list' => ':attributeはリスト形式で指定してください。',
    'lowercase' => ':attributeは小文字で入力してください。',
    'lt' => [
        'array' => ':attributeは:value個より少なく指定してください。',
        'file' => ':attributeは:value KBより小さいファイルにしてください。',
        'numeric' => ':attributeは:valueより小さい値にしてください。',
        'string' => ':attributeは:value文字より少なく入力してください。',
    ],
    'lte' => [
        'array' => ':attributeは:value個以下で指定してください。',
        'file' => ':attributeは:value KB以下のファイルにしてください。',
        'numeric' => ':attributeは:value以下の値にしてください。',
        'string' => ':attributeは:value文字以下で入力してください。',
    ],
    'mac_address' => ':attributeはMACアドレスの形式で入力してください。',
    'max' => [
        'array' => ':attributeは:max個以下で指定してください。',
        'file' => ':attributeは:max KB以下のファイルにしてください。',
        'numeric' => ':attributeは:max以下の値にしてください。',
        'string' => ':attributeは:max文字以内で入力してください。',
    ],
    'max_digits' => ':attributeは:max桁以内で入力してください。',
    'mimes' => ':attributeのファイル形式は:valuesのいずれかにしてください。',
    'mimetypes' => ':attributeのファイル形式は:valuesのいずれかにしてください。',
    'min' => [
        'array' => ':attributeは:min個以上で指定してください。',
        'file' => ':attributeは:min KB以上のファイルにしてください。',
        'numeric' => ':attributeは:min以上の値にしてください。',
        'string' => ':attributeは:min文字以上で入力してください。',
    ],
    'min_digits' => ':attributeは:min桁以上で入力してください。',
    'missing' => ':attributeは指定できません。',
    'missing_if' => ':otherが:valueの場合、:attributeは指定できません。',
    'missing_unless' => ':otherが:valueでない場合、:attributeは指定できません。',
    'missing_with' => ':valuesがある場合、:attributeは指定できません。',
    'missing_with_all' => ':valuesがすべてある場合、:attributeは指定できません。',
    'multiple_of' => ':attributeは:valueの倍数にしてください。',
    'not_in' => ':attributeに選択できない値が指定されています。',
    'not_regex' => ':attributeの形式が正しくありません。',
    'numeric' => ':attributeは数値で入力してください。',
    'password' => [
        'letters' => ':attributeには英字を1文字以上含めてください。',
        'mixed' => ':attributeには大文字と小文字を1文字以上ずつ含めてください。',
        'numbers' => ':attributeには数字を1文字以上含めてください。',
        'symbols' => ':attributeには記号を1文字以上含めてください。',
        'uncompromised' => ':attributeは漏えいが確認されています。別の値を指定してください。',
    ],
    'present' => ':attributeが指定されていません。',
    'present_if' => ':otherが:valueの場合、:attributeを指定してください。',
    'present_unless' => ':otherが:valueでない場合、:attributeを指定してください。',
    'present_with' => ':valuesがある場合、:attributeを指定してください。',
    'present_with_all' => ':valuesがすべてある場合、:attributeを指定してください。',
    'prohibited' => ':attributeは指定できません。',
    'prohibited_if' => ':otherが:valueの場合、:attributeは指定できません。',
    'prohibited_if_accepted' => ':otherを承認した場合、:attributeは指定できません。',
    'prohibited_if_declined' => ':otherを拒否した場合、:attributeは指定できません。',
    'prohibited_unless' => ':otherが:valuesでない場合、:attributeは指定できません。',
    'prohibits' => ':attributeがあると:otherは指定できません。',
    'regex' => ':attributeの形式が正しくありません。',
    'required' => ':attributeを入力してください。',
    'required_array_keys' => ':attributeには:valuesを含めてください。',
    'required_if' => ':otherが:valueの場合、:attributeを入力してください。',
    'required_if_accepted' => ':otherを承認した場合、:attributeを入力してください。',
    'required_if_declined' => ':otherを拒否した場合、:attributeを入力してください。',
    'required_unless' => ':otherが:valuesでない場合、:attributeを入力してください。',
    'required_with' => ':valuesがある場合、:attributeを入力してください。',
    'required_with_all' => ':valuesがすべてある場合、:attributeを入力してください。',
    'required_without' => ':valuesがない場合、:attributeを入力してください。',
    'required_without_all' => ':valuesがすべてない場合、:attributeを入力してください。',
    'same' => ':attributeと:otherには同じ値を指定してください。',
    'size' => [
        'array' => ':attributeは:size個で指定してください。',
        'file' => ':attributeは:size KBのファイルにしてください。',
        'numeric' => ':attributeは:sizeにしてください。',
        'string' => ':attributeは:size文字で入力してください。',
    ],
    'starts_with' => ':attributeは:valuesのいずれかで始まる必要があります。',
    'string' => ':attributeは文字列で入力してください。',
    'timezone' => ':attributeはタイムゾーンとして正しくありません。',
    'unique' => ':attributeはすでに登録されています。',
    'uploaded' => ':attributeのアップロードに失敗しました。',
    'uppercase' => ':attributeは大文字で入力してください。',
    'url' => ':attributeはURLの形式で入力してください。',
    'ulid' => ':attributeはULIDの形式で入力してください。',
    'uuid' => ':attributeはUUIDの形式で入力してください。',

    /*
     * 個別の項目に固有の文言。ルールの汎用文では意味が通らない場合だけここに書く。
     * 1つのフォームでしか使わない言い回しは FormRequest の messages() 側に置いている
     * （例: MailSettingRequest のクライアントIDの形式）。
     */
    'custom' => [
        // 項目名が「URL」なので汎用文だと「URLはURLの形式で…」と重なる
        'url' => [
            'url' => 'URLの形式で入力してください（http:// または https:// から始まる形）。',
        ],
    ],

    /*
     * :attributeに入る日本語の項目名。画面のラベルと同じ言い回しに揃える。
     * ここに無い項目は英語の列名がそのまま出るため、フォームを足したらここも足すこと。
     */
    'attributes' => [
        // 企業
        'name' => '企業名',
        'listed' => '上場',
        'position' => '募集ポジション',
        'legal_number' => '法人番号',
        'status' => '選考状況',
        'selection_notes' => '選考メモ',
        'favorite' => 'お気に入り',
        'confirm_duplicate' => '同名での登録の確認',
        'company_id' => '企業',

        // オファー
        'offer_deadline' => 'オファー回答期限',
        'offer_annual_salary' => 'オファー年収',
        'offer_notes' => 'オファーのメモ',
        'offer_base_monthly_yen' => 'オファーの基本給（月額）',
        'offer_fixed_overtime_hours' => 'オファーの固定残業時間',
        'offer_bonus_months' => 'オファーの賞与月数',

        // ソース（求人票・口コミ・ニュース）
        'type' => '種別',
        'origin' => '取り込み方法',
        'url' => 'URL',
        'title' => 'タイトル',
        'raw_text' => '本文',
        'text' => '本文',
        'summary' => '要約',

        // 面接記録
        'interviewed_on' => '面接日',
        'round' => '回次',
        'interviewer' => '面接官の役職',
        'asked' => '聞いたこと',
        'answers' => '回答・聞けたこと',
        'impressions' => '所感',

        // 予定
        'kind' => '種別',
        'starts_at' => '日時',
        'place' => '場所',
        'note' => 'メモ',

        // 論点
        'claim' => '主張',
        'verdict' => '判定',
        'rationale' => '根拠',
        'resolution_note' => '解消の根拠',

        // 公式数値
        'source' => '出典',
        'key' => '項目',
        'value' => '値',
        'unit' => '単位',
        'fiscal_year' => '年度',

        // プロファイル（設定）
        'career_summary' => 'キャリア要約',
        'must' => '必須条件',
        'want' => '歓迎条件',
        'ng' => 'NG条件',
        'axes' => '判断軸',
        'current_annual_salary' => '現年収',
        'current_base_monthly_yen' => '現在の基本給（月額）',
        'current_fixed_overtime_hours' => '現在の固定残業時間',
        'current_bonus_months' => '現在の賞与月数',

        // AI・メールの設定
        'provider' => 'AIの実行先',
        'model' => 'モデル',
        'api_key' => 'APIキー',
        'google_client_id' => 'クライアントID',
        'google_client_secret' => 'クライアントシークレット',
        'allowed_domains' => '取り込む差出人ドメイン',
        'mask_words' => 'AIに渡す前に伏せる語',
        'auto_fetch' => '取り込み方法',

        // 分析
        'panel_size' => '裏取りの人数',
        'reviewed' => '確認済み',
    ],

];
