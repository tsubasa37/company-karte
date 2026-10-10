<?php

namespace App\Http\Requests;

use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSourceDocumentRequest extends FormRequest
{
    /** 貼り付け本文の長さ上限。クリップAPI（ClipController）と同じ値に揃える */
    private const MAX_RAW_TEXT_LENGTH = 2000000;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(SourceType::class)],
            'origin' => ['required', Rule::enum(SourceOrigin::class)],
            // クリップAPI（url:http,https）と同じ基準。javascript: 等のスキームを保存させない
            'url' => ['nullable', 'url:http,https', 'required_if:origin,url'],
            'raw_text' => ['nullable', 'string', 'max:'.self::MAX_RAW_TEXT_LENGTH, 'required_if:origin,paste'],
        ];
    }

    /** URL取得のソースか（貼り付けなら false） */
    public function isUrlOrigin(): bool
    {
        return $this->validated('origin') === SourceOrigin::Url->value;
    }
}
