<?php

namespace App\Http\Controllers;

use App\Application\Dashboard\GetOperationalDashboard;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, GetOperationalDashboard $dashboard): View
    {
        return view('dashboard', $dashboard->for($request->user()));
    }
}
