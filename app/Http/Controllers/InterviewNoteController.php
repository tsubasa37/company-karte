<?php

namespace App\Http\Controllers;

use App\Actions\StoreInterviewNoteAction;
use App\Http\Requests\StoreInterviewNoteRequest;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;

class InterviewNoteController extends Controller
{
    public function store(StoreInterviewNoteRequest $request, Company $company, StoreInterviewNoteAction $action): RedirectResponse
    {
        $action->execute($company, $request->validated());

        return back();
    }
}
