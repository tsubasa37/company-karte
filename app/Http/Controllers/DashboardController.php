<?php

namespace App\Http\Controllers;

use App\Actions\BuildDashboardAction;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * execute() は props をクロージャで返す。実行中ランのポーリング（only=['running']）では
     * Inertia が running のクロージャしか呼ばないため、全体集計は再計算されない。
     */
    public function index(BuildDashboardAction $action): Response
    {
        return Inertia::render('Dashboard', $action->execute());
    }
}
