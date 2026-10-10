<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInterviewNoteRequest extends FormRequest
{
    /** 自由記述の長さ上限。面接メモは長文になるが、貼り付け本文ほどの桁は要らない */
    private const MAX_NOTE_LENGTH = 100000;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'interviewed_on' => ['required', 'date'],
            'round' => ['required', 'string', 'max:50'],
            'interviewer' => ['nullable', 'string', 'max:100'],
            'asked' => ['nullable', 'string', 'max:'.self::MAX_NOTE_LENGTH],
            'answers' => ['required', 'string', 'max:'.self::MAX_NOTE_LENGTH],
            'impressions' => ['nullable', 'string', 'max:'.self::MAX_NOTE_LENGTH],
        ];
    }
}
