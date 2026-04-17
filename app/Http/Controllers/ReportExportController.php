<?php

namespace App\Http\Controllers;

use App\Exports\InstructorPerformanceExport;
use App\Exports\RevenueExport;
use App\Exports\SessionLogExport;
use App\Exports\SafetyIncidentExport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportExportController extends Controller
{
    /**
     * Export instructor performance report
     */
    public function instructorPerformance(): BinaryFileResponse
    {
        $filename = 'instructor_performance_' . now()->format('Y-m-d') . '.xlsx';
        
        return Excel::download(new InstructorPerformanceExport, $filename);
    }

    /**
     * Export revenue report
     */
    public function revenue(Request $request): BinaryFileResponse
    {
        $startDate = $request->date('start_date');
        $endDate = $request->date('end_date');
        
        $filename = 'revenue_' . ($startDate ? $startDate->format('Y-m-d') : now()->format('Y-m')) . '.xlsx';
        
        return Excel::download(new RevenueExport($startDate, $endDate), $filename);
    }

    /**
     * Export session logs
     */
    public function sessionLogs(Request $request): BinaryFileResponse
    {
        $startDate = $request->date('start_date');
        $endDate = $request->date('end_date');
        
        $filename = 'session_logs_' . ($startDate ? $startDate->format('Y-m-d') : now()->format('Y-m')) . '.xlsx';
        
        return Excel::download(new SessionLogExport($startDate, $endDate), $filename);
    }

    /**
     * Export safety incidents
     */
    public function safetyIncidents(Request $request): BinaryFileResponse
    {
        $startDate = $request->date('start_date');
        $endDate = $request->date('end_date');
        
        $filename = 'safety_incidents_' . ($startDate ? $startDate->format('Y-m-d') : now()->format('Y-m')) . '.xlsx';
        
        return Excel::download(new SafetyIncidentExport($startDate, $endDate), $filename);
    }
}
