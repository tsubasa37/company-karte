/** 予定1件の描画データ。整形・判定は PHP(ScheduleEventPresenter) 側で確定済み */
export interface ScheduleEvent {
    id: number;
    companyId: number;
    companyName: string;
    kind: string;
    kindLabel: string;
    /** メール由来なら「📧 メール」。手入力は null（由来が自明なのでバッジを出さない） */
    sourceBadge: string | null;
    sourceLabel: string;
    title: string | null;
    /** 「面接（2次）」のような表示名 */
    displayTitle: string;
    /** input[type=datetime-local] にそのまま入る形 */
    startsAt: string;
    date: string;
    time: string;
    /** 「4/10(金) 10:00」 */
    dateLabel: string;
    durationMinutes: number | null;
    place: string | null;
    /** http/https のときだけ入る。null ならリンクにしない */
    placeUrl: string | null;
    note: string | null;
    icsUrl: string;
    past: boolean;
}

/** 時系列リストの区分（今週/来週/それ以降/過去）。空の区分は PHP 側で落としてある */
export interface ScheduleGroup {
    key: string;
    label: string;
    events: ScheduleEvent[];
}

export interface CalendarDay {
    date: string;
    day: number;
    isToday: boolean;
    isCurrentMonth: boolean;
    events: ScheduleEvent[];
}

/** 月カレンダー。7列×N行のグリッドは PHP 側で組み立て済み */
export interface ScheduleCalendar {
    month: string;
    label: string;
    prevMonth: string;
    nextMonth: string;
    weekdays: string[];
    weeks: CalendarDay[][];
}

export interface ScheduleCompanyOption {
    id: number;
    name: string;
}
