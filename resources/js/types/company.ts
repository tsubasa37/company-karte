export interface Option {
    value: string;
    label: string;
}

/** 分析時に選ぶ「裏取り（P2）の判定者の人数」の選択肢（PHP の VerdictPerspective が確定する） */
export interface PanelSizeOption {
    value: number;
    label: string;
}

export interface StepMeta {
    step: string;
    label: string;
    status: string;
    statusLabel: string;
}

export interface RunMeta {
    id: number;
    status: string;
    statusLabel: string;
    isRunning: boolean;
    /** 実行中のまま更新が途絶えたか（判定は PHP の StuckRunDetector。true なら手動で中断できる） */
    isStuck: boolean;
    /** 最後に何か書き込まれた日時（'Y-m-d H:i'）。応答なしの説明に使う */
    lastActivityAt: string | null;
    /** この分数以上更新が無いと応答なしとみなす（キューの再予約時間から PHP が確定） */
    stuckAfterMinutes: number;
    currentStepLabel: string | null;
    /** このランが裏取り（P2）を何人で判定したか。多重化前のランは 1 */
    panelSize: number;
    error: string | null;
    steps: StepMeta[];
}

export interface Source {
    id: number;
    type: string;
    typeLabel: string;
    origin: string;
    url: string | null;
    /** URL のホスト名（貼り付けソースは null）。どのサイト由来かの識別用 */
    host: string | null;
    /** クリップ時のページタイトル（画面フォーム投入などで無ければ null） */
    title: string | null;
    /** クリップ時に AI が生成した一行要約（無ければ excerpt を表示する） */
    summary: string | null;
    excerpt: string;
    length: number;
    /** 分析に渡る上限（PHP の limitFor と同一の値） */
    limit: number;
    /** 分析で切り捨てが起きるか（判定は PHP。口コミは分割済みが材料なので常に false） */
    isTruncated: boolean;
    fetchedAt: string | null;
    createdAt: string | null;
    isReview: boolean;
    /** 口コミの分割完了日時（口コミ以外・未分割は null） */
    splitAt: string | null;
    /** 分割済み口コミ件数（口コミ以外は null） */
    reviewItemCount: number | null;
    /** 再取り込みで本文が変わった回数 */
    revisionCount: number;
}

export interface Metric {
    id: number;
    sourceLabel: string;
    /** 出典ページ（取得元へ飛べる。手入力等で無ければ null） */
    sourceUrl: string | null;
    key: string;
    value: string;
    unit: string | null;
    fiscalYear: string | null;
}

/** 口コミソースをAIで「1口コミ=1件」に分割したもの */
export interface ReviewItemView {
    id: number;
    stance: string | null;
    /** 投稿・回答された時期（投稿日/回答日）。未取得なら null */
    postedPeriod: string | null;
    /** 投稿者が在籍していた時期。未取得なら null */
    tenurePeriod: string | null;
    category: string | null;
    /** 投稿者の職種（p4の職種グループ化に使う）。未取得なら null */
    jobType: string | null;
    /** 由来の口コミサイトのホスト名。手貼り（URLなし）なら null */
    sourceHost: string | null;
    body: string;
    /** 新旧の判定（PHPで確定。投稿時期優先・無ければ在籍時期。「最近2年以内」「2年より前」「時期不明」） */
    periodLabel: string;
    isRecent: boolean;
}

/** 再取り込みで本文が変わった1回分の履歴（隣接バージョン間の変わった行のみ） */
export interface RevisionView {
    id: number;
    replacedAt: string | null;
    diff: {
        /** 表示用の変化行（PHP 側で先頭 MAX_LINES 件に打ち切り済み） */
        lines: { type: 'add' | 'del'; line: string }[];
        /** 打ち切り前の総追加行数 */
        addTotal: number;
        /** 打ち切り前の総削除行数 */
        delTotal: number;
        /** lines が打ち切られているか（省略行数は omitted） */
        truncated: boolean;
        omitted: number;
    };
}

/** ソース詳細ページ用（本文を分析対象/切り捨ての2つに分けて受け取る） */
export interface SourceDetail {
    id: number;
    typeLabel: string;
    origin: string;
    /** 由来の表示名（ペースト / URL / メール）。文言は PHP の Enum が確定する */
    originLabel: string;
    url: string | null;
    length: number;
    fetchedAt: string | null;
    createdAt: string | null;
    analyzedText: string;
    truncatedText: string;
    /** 分割処理の完了日時（口コミ以外・未分割は null） */
    splitAt: string | null;
    /** 口コミソースの分割結果（口コミ以外は null） */
    reviewItems: ReviewItemView[] | null;
    /** 再取り込みでの文言変化の履歴（新しい順） */
    revisions: RevisionView[];
}

export interface CompanyRef {
    id: number;
    name: string;
}

/** 面接前ブリーフィング（生成物と生成状態） */
export interface Briefing {
    data: {
        summary_lines: string[];
        open_issues: string[];
        top_questions: string[];
        weak_points: string[];
        note: string;
    } | null;
    generatedAt: string | null;
    isGenerating: boolean;
    error: string | null;
    /** 生成後にカルテ・面接記録が更新された場合の陳腐化理由（未生成・最新なら null） */
    staleReason: string | null;
}

/** 求人票からAIで構造化抽出した労働条件（記載の抜き出しのみ・推測なし） */
export interface ConditionSet {
    salary_min: number | null;
    salary_max: number | null;
    salary_text: string;
    remote: string;
    flex: string;
    /** 残業代の扱いの原文表記（構造化した時間・金額は下の2キー） */
    overtime_pay: string;
    location: string;
    holidays: string;
    notes: string;
    /** 固定残業（みなし残業）の時間数。実質時給の PHP 確定計算に使う。記載なしは null */
    fixed_overtime_hours: number | null;
    /** 固定残業代の金額（円）。記載なしは null */
    fixed_overtime_amount_yen: number | null;
    /** 月給の下限・上限（円）。年収のみの記載なら null */
    monthly_salary_min_yen: number | null;
    monthly_salary_max_yen: number | null;
}

/** 公式データの平均年間給与（有報/手入力。未登録は null） */
export interface AvgSalary {
    value: string;
    unit: string | null;
    fiscalYear: string | null;
}

/** 年収レンジ幅の構造シグナル（倍率・差額は PHP の SalaryRangeSignal が確定計算） */
export interface SalaryRangeSignal {
    ratio: number | null;
    spread: number | null;
    /** しきい値（2.0倍）以上に開いているか */
    isWide: boolean;
    /** 下限・上限のどちらかに数値の記載があるか（両方無ければ注記を出さない） */
    hasRange: boolean;
    /** 算出できない理由（算出できたら null）。0除算を避けた場合もここに理由が入る */
    unavailableReason: string | null;
    /** 注意喚起の文言（しきい値未満なら null） */
    note: string | null;
}

/** 比較ビューの1行 */
export interface CompareRow {
    id: number;
    name: string;
    status: string;
    statusLabel: string;
    recommendation: string | null;
    recommendationLabel: string | null;
    /** おすすめ度の判定理由。キーが無い旧ランは null（画面は「未保存」と明示する） */
    recommendationReason: string | null;
    /** P5 の懸念点。キーが無い旧ランは null（画面は「未保存」と明示する） */
    concern: string | null;
    analyzed: boolean;
    /** 最新の完了ランの終了日（未分析は null） */
    analyzedAt: string | null;
    mustMet: number;
    mustUnmet: number;
    mustTotal: number;
    wantMet: number;
    wantTotal: number;
    /** NG条件の判定件数（hit=抵触/unknown=判定不能。ng_checks 導入前のランは total 0） */
    ngHit: number;
    ngUnknown: number;
    ngTotal: number;
    /** ng_checks がこのランに保存されているか（false = 旧ラン。「該当なし」と区別して未保存を明示） */
    ngSaved: boolean;
    contradicts: number;
    /** 判定者の意見が分かれた主張の件数（1人で分析した企業は常に 0） */
    splits: number;
    unverifiables: number;
    conditions: ConditionSet | null;
    /** 条件抽出の材料（求人票/その他）が投入済みか。null条件の理由を描き分けるのに使う */
    hasConditionSource: boolean;
    /** 条件が未抽出（conditions === null）なら null */
    salaryRange: SalaryRangeSignal | null;
    avgSalary: AvgSalary | null;
    /** オファー年収（万円）と現年収との差（PHPで確定計算済み） */
    offerSalary: number | null;
    offerDelta: number | null;
    /** 固定残業込み・賞与依存のオファーで単純差額を良条件と誤読させないための注記（無ければ null） */
    offerCaveat: string | null;
}

/** EDINET取得の状態 */
export interface EdinetStatus {
    isFetching: boolean;
    fetchedAt: string | null;
    error: string | null;
    /** 押す前から分かる「取得できない理由」（取得可能なら null。判定は PHP） */
    blockedReason: string | null;
    gbizBlockedReason: string | null;
}

/** 分析を開始できない理由（開始可能なら null。判定は PHP の AnalysisGuard） */
export interface AnalyzeBlocked {
    message: string;
    /** 解消に向かう画面。settings = プロファイル設定 / ai = AIエンジン設定 */
    action: 'sources' | 'settings' | 'ai' | null;
}

/** ダッシュボードの企業サマリ */
export interface DashboardCompany {
    id: number;
    name: string;
    recommendationSymbol: string | null;
    /** 面接前ブリーフィング生成済みか（🎤マーカー用） */
    hasBriefing: boolean;
    /** ★お気に入りか（選考ステータス別の見出しに出す★の件数に使う） */
    favorite: boolean;
}

/** ダッシュボードのアラート1ブロック（上位件数の切り出しと残数はPHPで確定済み） */
export interface AlertBlock<T> {
    items: T[];
    total: number;
    more: number;
}

/** キュー実処理で失敗した failed_jobs の行（⛔要対応） */
export interface FailedJobAlert {
    id: number;
    jobName: string;
    excerpt: string;
    failedAt: string | null;
}

/** 最新の分析ランが失敗したままの企業（⛔要対応） */
export interface FailedRunAlert extends DashboardCompany {
    runId: number;
    error: string;
    failedAt: string | null;
}

/** ブリーフィング生成・EDINET取得が失敗したままの企業（⛔要対応） */
export interface ErrorCompanyAlert extends DashboardCompany {
    kind: string;
    error: string;
}

/** ダッシュボードのステータス別グループ */
export interface StatusGroup {
    status: string;
    label: string;
    /** 選考が動いている段階か。false（気になる・見送り）は企業名を並べず、件数と一覧への導線だけ出す */
    active: boolean;
    companies: DashboardCompany[];
}

export interface Company {
    id: number;
    name: string;
    listed: boolean;
    position: string | null;
    legalNumber: string | null;
    /** EDINET 取得成功時にキャッシュされるコード（未取得は null） */
    edinetCode: string | null;
    status: string;
    statusLabel: string;
    recommendation: string | null;
    recommendationLabel: string | null;
    recommendationSymbol: string | null;
    selectionNotes: string | null;
    /** オファー回答期限（Y-m-d。未設定は null）。面接日程は ScheduleEvent 側が持つ */
    offerDeadline: string | null;
    /** オファー条件（未提示は null） */
    offerAnnualSalary: number | null;
    offerNotes: string | null;
    /** オファーの内訳（基本給・固定残業・賞与。未入力は null） */
    offerBaseMonthlyYen: number | null;
    offerFixedOvertimeHours: number | null;
    offerBonusMonths: number | null;
    /** 労基公表リスト突合でヒットした場合の注記（未ヒット・未突合は null） */
    laborNote: string | null;
    /** 最後に突合した日時（未突合は null）。null と「一致なし」を画面で区別するために使う */
    laborCheckedAt: string | null;
    /** 過去に一致していた注記の履歴（新しい順。無ければ空配列） */
    laborNoteHistory: LaborNoteHistoryEntry[];
}

/** 一度一致した注記が消えた記録（公表リストは掲載期間でローテーションするため残す） */
export interface LaborNoteHistoryEntry {
    note: string;
    /** 消えた理由（社名一致なし・突合対象外 等） */
    reason: string;
    clearedAt: string | null;
}

/** 企業一覧の1行 */
export interface CompanyRow {
    id: number;
    name: string;
    listed: boolean;
    position: string | null;
    /** お気に入り（★）。並び替えで常に最上位に来る */
    favorite: boolean;
    status: string;
    statusLabel: string;
    recommendation: string | null;
    recommendationLabel: string | null;
    recommendationSymbol: string | null;
    updatedAt: string | null;
}

/** 設定画面のプロファイル */
export interface ProfileData {
    career_summary: string | null;
    must_json: string[] | null;
    want_json: string[] | null;
    ng_json: string[] | null;
    axis: string | null;
    current_annual_salary: number | null;
}

/** 基本情報タブのフォーム（子コンポーネントへ渡して入力欄を分割するための型） */
export interface CompanyInfoForm {
    name: string;
    listed: boolean;
    position: string | null;
    legal_number: string | null;
    status: string;
    selection_notes: string;
    offer_deadline: string;
    offer_annual_salary: number | null;
    offer_notes: string;
    offer_base_monthly_yen: number | null;
    offer_fixed_overtime_hours: number | null;
    offer_bonus_months: number | null;
}
