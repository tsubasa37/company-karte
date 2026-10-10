<?php

namespace App\Http\Controllers;

use App\Jobs\LaborCheckJob;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;

class LaborCheckController extends Controller
{
    public function store(): RedirectResponse
    {
        if (Company::count() === 0) {
            return back()->withErrors(['labor' => '突合する企業が登録されていません。']);
        }

        LaborCheckJob::dispatch();

        return back();
    }
}
