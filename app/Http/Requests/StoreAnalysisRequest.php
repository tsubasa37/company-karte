<?php

namespace App\Http\Requests;

use App\Enums\VerdictPerspective;
use Illuminate\Foundation\Http\FormRequest;

class StoreAnalysisRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // P2（裏取り）を何人で判定するか。画面のセレクタが必ず送る値なので、
            // 未指定を既定値で埋めず required にする（暗黙の既定で人数が変わらないようにする）
            'panel_size' => ['required', 'integer', 'min:1', 'max:'.VerdictPerspective::maxPanelSize()],
        ];
    }

    public function panelSize(): int
    {
        return (int) $this->validated('panel_size');
    }
}
