<?php

namespace App\Http\Controllers;

use App\Actions\BuildCompareRowsAction;
use App\Enums\CompanyStatus;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CompareController extends Controller
{
    public function index(Request $request, BuildCompareRowsAction $action): Response
    {
        // 未知のステータスは黙って全件表示にせず、バリデーションエラーにする
        $request->validate(['status' => ['nullable', Rule::enum(CompanyStatus::class)]]);

        $ids = $request->query('ids');
        // ?ids=5（非配列）でも黙って全社表示にせず、1件指定として扱う
        $ids = $ids !== null ? array_map('intval', (array) $ids) : null;

        return Inertia::render('Compare', $action->execute(
            $ids,
            $request->boolean('all'),
            $request->enum('status', CompanyStatus::class),
        ));
    }
}
