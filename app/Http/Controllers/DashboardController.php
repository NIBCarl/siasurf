<?php

namespace App\Http\Controllers;

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
     * Display the admin dashboard
     */
    public function index(): Response
    {
        $stats = $this->dashboardService->getOverviewStats();
        $activity = $this->dashboardService->getRecentActivity(10);
        $ongoingSessions = $this->dashboardService->getOngoingSessions(10);

        return Inertia::render('Admin/Dashboard', [
            'stats' => $stats,
            'activity' => $activity,
            'ongoingSessions' => $ongoingSessions,
        ]);
    }

    /**
     * Get dashboard statistics (API)
     */
    public function stats()
    {
        return response()->json([
            'overview' => $this->dashboardService->getOverviewStats(),
        ]);
    }

    /**
     * Get revenue data for charts
     */
    public function revenue(Request $request)
    {
        $period = $request->get('period', 'monthly');
        $months = $request->integer('months', 12);

        return response()->json([
            'revenue' => $this->dashboardService->getRevenueData($period, $months),
        ]);
    }

    /**
     * Get booking trends
     */
    public function bookingTrends(Request $request)
    {
        $days = $request->integer('days', 30);

        return response()->json([
            'trends' => $this->dashboardService->getBookingTrends($days),
        ]);
    }

    /**
     * Get popular surf spots
     */
    public function popularSpots(Request $request)
    {
        $limit = $request->integer('limit', 5);

        return response()->json([
            'spots' => $this->dashboardService->getPopularSurfSpots($limit),
        ]);
    }

    /**
     * Get top instructors
     */
    public function topInstructors(Request $request)
    {
        $limit = $request->integer('limit', 10);

        return response()->json([
            'instructors' => $this->dashboardService->getTopInstructors($limit),
        ]);
    }

    /**
     * Get recent activity
     */
    public function activity(Request $request)
    {
        $limit = $request->integer('limit', 20);

        return response()->json([
            'activity' => $this->dashboardService->getRecentActivity($limit),
        ]);
    }

    /**
     * Get safety statistics
     */
    public function safetyStats()
    {
        return response()->json([
            'safety' => $this->dashboardService->getSafetyStats(),
        ]);
    }

    /**
     * Get booking status distribution
     */
    public function bookingDistribution()
    {
        return response()->json([
            'distribution' => $this->dashboardService->getBookingStatusDistribution(),
        ]);
    }

    /**
     * Display the analytics page
     */
    public function analytics(): Response
    {
        return Inertia::render('Admin/Analytics/Index', [
            'analytics' => [
                'revenue' => $this->dashboardService->getRevenueData(),
                'bookings' => $this->dashboardService->getBookingTrends(),
                'spots' => $this->dashboardService->getPopularSurfSpots(),
            ]
        ]);
    }
}
