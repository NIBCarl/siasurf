<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SafetyIncident;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IncidentController extends Controller
{
    /**
     * Display a listing of safety incidents.
     */
    public function index(): Response
    {
        $incidents = SafetyIncident::with(['instructor', 'booking'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return Inertia::render('Admin/Incidents/Index', [
            'incidents' => $incidents,
        ]);
    }

    /**
     * Show the form for creating a new incident.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Incidents/Create', [
            'instructors' => \App\Models\User::where('role', 'instructor')
                ->with('instructorProfile')
                ->get(),
            'bookings' => \App\Models\Booking::with(['student', 'instructor'])
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(),
        ]);
    }

    /**
     * Store a newly created incident.
     */
    public function store(Request $request)
    {
        // Logic to store incident and potentially trigger strike counting
    }

    /**
     * Display the specified incident.
     */
    public function show(SafetyIncident $incident): Response
    {
        $incident->load(['instructor', 'booking', 'reporter']);

        return Inertia::render('Admin/Incidents/Show', [
            'incident' => $incident,
        ]);
    }
}
