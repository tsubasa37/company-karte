<?php

namespace App\Http\Requests;

use App\Enums\ScheduleKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 予定の登録・更新に共通のバリデーション（項目が同一のため1本に寄せる）。
 */
class ScheduleEventRequest extends FormRequest
{
    /**
     * place にリンク化できないスキームが入るのを止める。
     * 画面側は http/https のときだけ <a> にするが、保存時にも弾いておく。
     */
    private const BLOCKED_SCHEMES = '/^\s*(?:javascript|data|vbscript|file|blob)\s*:/i';

    /** 備考の長さ上限。面接メモ（StoreInterviewNoteRequest）と同じ値に揃える */
    private const MAX_NOTE_LENGTH = 100000;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'kind' => ['required', Rule::enum(ScheduleKind::class)],
            'title' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            // 1日を超える面接は想定しない（打ち間違いの歯止め）
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'place' => ['nullable', 'string', 'max:255', $this->safePlace(...)],
            'note' => ['nullable', 'string', 'max:'.self::MAX_NOTE_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'company_id.required' => '企業を選んでください。',
            'starts_at.required' => '開始日時を入力してください。',
        ];
    }

    private function safePlace(string $attribute, mixed $value, \Closure $fail): void
    {
        if (is_string($value) && preg_match(self::BLOCKED_SCHEMES, $value) === 1) {
            $fail('場所・URLに使用できない形式が含まれています（http / https のみリンクにできます）。');
        }
    }
}
