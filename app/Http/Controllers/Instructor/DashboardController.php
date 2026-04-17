<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    protected DashboardService $dashboardService;

    public function __construct(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    /**
     * Display the instructor dashboard.
     */
    public function index(Request $request): Response
    {
        $data = $this->dashboardService->getInstructorDashboardData($request->user());

        return Inertia::render('Instructor/Dashboard', $data);
    }
}
