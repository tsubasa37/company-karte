<?php

namespace App\Http\Controllers;

use App\Enums\MetricSource;
use App\Models\Company;
use App\Models\OfficialMetric;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OfficialMetricController extends Controller
{
    public function store(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'source' => ['required', Rule::enum(MetricSource::class)],
            'key' => ['required', 'string', 'max:255'],
            'value' => ['required', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:50'],
            'fiscal_year' => ['nullable', 'string', 'max:50'],
            // 画面フォーム・クリップAPIと同じ基準。素の url ルールは data: blob: 等を通すため
            // スキームを http/https に限定する（この値は画面で <a href> になる）
            'source_url' => ['nullable', 'url:http,https'],
        ]);

        $company->officialMetrics()->create($data);

        return back();
    }

    public function destroy(OfficialMetric $metric): RedirectResponse
    {
        $metric->delete();

        return back();
    }
}
