<?php

namespace App\Http\Controllers;

use App\Actions\SearchDocumentsAction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    public function index(Request $request, SearchDocumentsAction $action): Response
    {
        $request->validate(['q' => ['nullable', 'string', 'max:200']]);

        return Inertia::render('Search', $action->execute($request->query('q')));
    }
}
