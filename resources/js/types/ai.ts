/** 用途の重さ（PHP の AiTier）。ai_settings の鍵と一致 */
export type AiTierKey = 'analysis' | 'light';

/** 実行先（PHP の AiProvider）の値 */
export type AiProviderKey = 'codex' | 'openai' | 'gemini' | 'anthropic' | 'jev';

/** 選択肢1つ */
export interface AiOptionChoice {
    value: string;
    label: string;
}

/** プロバイダ固有オプションの入力項目。PHP(ProviderOptionFields) が返すので画面は増やさない */
export interface AiOptionField {
    key: string;
    label: string;
    /** 'select' | 'number'。未対応の型は画面が黙って別の入力に落とさず、その旨を出す */
    type: string;
    /** select のときだけ埋まる */
    choices: AiOptionChoice[];
    /** number のときだけ埋まる入力範囲。select では null */
    min: number | null;
    max: number | null;
    step: number | null;
    help: string | null;
}

/** モデル選択の候補1件。単価は100万トークンあたりの USD で、金額の確定は PHP 側で済んでいる */
export interface AiModelOption {
    name: string;
    inputUsd: number;
    outputUsd: number;
    /** 長い入力で単価が上がるモデルか（表示している単価は通常の帯のもの） */
    hasBands: boolean;
    /** 1社の分析にかかる費用の目安（マイクロUSD）。単価未登録なら null */
    perCompanyMicroUsd: number | null;
    /** 同じ目安の円換算。レートは PHP 側が持つ（画面では換算しない） */
    perCompanyJpy: number | null;
}

/** 設定画面に出すプロバイダ1件の状態。APIキーは返らず「登録済みか」だけが来る */
export interface AiProviderStatus {
    provider: AiProviderKey;
    label: string;
    /** サブプロセス実行（codex）。APIキーを持たない */
    isSubprocess: boolean;
    /** .env にキーが設定済みか（値は画面へ来ない） */
    hasApiKey: boolean;
    /** キーを書く .env の変数名。codex はキーを持たないので null */
    apiKeyEnvName: string | null;
    /** ドライバが実装済みか。false のプロバイダはキーを設定しても実行先には選べない */
    driverReady: boolean;
    modelAnalysis: string | null;
    modelLight: string | null;
    /** モデル名の入力欄に出す例（単価表に載っているモデル） */
    modelPlaceholder: string;
    /** モデル選択の候補。単価表に載っているものだけで、安い順に届く */
    models: AiModelOption[];
    /** Tier ごとの固有オプションの現在値。項目の鍵は必ず埋まる（未設定は空文字）。数値項目は数値で届く */
    options: Record<AiTierKey, Record<string, string | number>>;
    docsUrl: string;
    fields: AiOptionField[];
    /** データの送り先について画面に出す注記 */
    notes: string[];
}

/** その Tier に今選ばれている実行先 */
export interface AiCurrentTarget {
    provider: AiProviderKey;
    providerLabel: string;
    /** 未設定なら null（実行時に例外で止まる） */
    model: string | null;
}

/** 判定エンジン（判定専用の実行先）1件の状態。APIキーは返らず「登録済みか」だけが来る */
export interface AiDecisionProviderStatus {
    provider: AiProviderKey;
    label: string;
    hasApiKey: boolean;
    apiKeyEnvName: string | null;
    modelDecision: string | null;
    modelPlaceholder: string;
    /** 単価表に載っているモデル。判定は1社あたりの目安を出さないので perCompany* は来ない */
    models: Pick<AiModelOption, 'name' | 'inputUsd' | 'outputUsd' | 'hasBands'>[];
    docsUrl: string;
    notes: string[];
    /** 1回の呼び出しの上限秒 */
    timeout: number;
}

/** 判定エンジンの状態。Tier の実行先とは別枠で、provider が null なら機能オフ */
export interface AiDecisionStatus {
    provider: AiProviderKey | null;
    /** 裏取り（P2）に渡す口コミを機械判定の関連度順に選ぶか */
    reviewOrdering: boolean;
    /** 面接メモを判定エンジンに送り、論点の消し込みを機械判定にするか（同意スイッチ） */
    sendInterviewNotes: boolean;
    /** 判定エンジンに選べる実行先（キー登録済み） */
    available: AiProviderKey[];
    providers: AiDecisionProviderStatus[];
}

/** 設定画面に出す AI エンジンの状態 */
export interface AiStatus {
    routing: Record<AiTierKey, AiProviderKey>;
    /** 実行先に選べるプロバイダ（ドライバ実装済み かつ codex または キー登録済み） */
    available: AiProviderKey[];
    current: Record<AiTierKey, AiCurrentTarget>;
    providers: AiProviderStatus[];
    decision: AiDecisionStatus;
}

/** Tier ごとの実行先フォーム */
export interface AiRoutingForm {
    analysis_provider: AiProviderKey;
    light_provider: AiProviderKey;
}

/** Tier セレクトの並び（画面の並び順と送信名） */
export interface AiTierField {
    key: AiTierKey;
    name: keyof AiRoutingForm;
    label: string;
    hint: string;
}

/** プロバイダ設定フォームの Tier ごとの並び（ラベルとモデルの送信名） */
export interface AiProviderTierField {
    key: AiTierKey;
    label: string;
    model: 'model_analysis' | 'model_light';
}

/** プロバイダごとの接続設定フォーム。APIキーは空のまま送れば据え置き */
export interface AiProviderConfigForm {
    model_analysis: string;
    model_light: string;
    /** number 項目は入力すると数値になる。空文字は「未設定」として保存時に鍵ごと落ちる */
    options: Record<AiTierKey, Record<string, string | number>>;
}

/** 接続テストの結果（押したときだけ flash で届く。どのプロバイダの結果かを添える） */
export interface AiTestResult {
    provider: AiProviderKey;
    ok: boolean;
    message: string;
}

/** 判定エンジンのフォーム。provider が空なら機能オフ（モデルは消さずに残る） */
export interface AiDecisionForm {
    provider: AiProviderKey | '';
    model: string;
    /** オフ（provider 空）で保存すると false に戻る */
    review_ordering: boolean;
    /** 同上（オフで保存すると false に戻る） */
    send_interview_notes: boolean;
}

/** 判定エンジンの接続テストの結果（flash aiDecisionTest。未設定で押したときは provider が null） */
export interface AiDecisionTestResult {
    provider: AiProviderKey | null;
    ok: boolean;
    message: string;
}
