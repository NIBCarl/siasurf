<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\Availability;
use App\Enums\DayOfWeek;
use App\Enums\TimePeriod;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AvailabilityController extends Controller
{
    /**
     * Display instructor's availability
     */
    public function index(): Response
    {
        $user = auth()->user();

        $availabilities = Availability::where('user_id', $user->id)
            ->orderBy('day_of_week')
            ->orderBy('time_period')
            ->get()
            ->groupBy('day_of_week');

        return Inertia::render('Instructor/Availability/Index', [
            'availabilities' => $availabilities,
            'daysOfWeek' => collect(DayOfWeek::cases())->map(fn($day) => [
                'name' => $day->name,
                'value' => $day->value,
                'label' => $day->label(),
            ]),
            'timePeriods' => collect(TimePeriod::cases())->map(fn($period) => [
                'name' => $period->name,
                'value' => $period->value,
                'label' => $period->label(),
            ]),
            'profile' => $user->instructorProfile,
        ]);
    }

    /**
     * Update weekly recurring availability
     */
    public function updateWeekly(Request $request): RedirectResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'availabilities' => 'required|array',
            'availabilities.*.day_of_week' => 'required|integer|between:0,6',
            'availabilities.*.time_period' => 'required|in:morning,afternoon',
            'availabilities.*.is_available' => 'required|boolean',
        ]);

        foreach ($validated['availabilities'] as $availability) {
            Availability::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'day_of_week' => $availability['day_of_week'],
                    'time_period' => $availability['time_period'],
                    'specific_date' => null,
                ],
                [
                    'is_available' => $availability['is_available'],
                ]
            );
        }

        return back()->with('success', 'Weekly availability updated successfully.');
    }

    /**
     * Block specific dates
     */
    public function blockDates(Request $request): RedirectResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'dates' => 'required|array',
            'dates.*' => 'required|date|after_or_equal:today',
            'time_period' => 'nullable|in:morning,afternoon',
        ]);

        foreach ($validated['dates'] as $date) {
            if ($validated['time_period']) {
                // Block specific time period
                Availability::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'specific_date' => $date,
                        'time_period' => $validated['time_period'],
                    ],
                    [
                        'day_of_week' => null,
                        'is_available' => false,
                    ]
                );
            } else {
                // Block entire day (both periods)
                foreach (['morning', 'afternoon'] as $period) {
                    Availability::updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'specific_date' => $date,
                            'time_period' => $period,
                        ],
                        [
                            'day_of_week' => null,
                            'is_available' => false,
                        ]
                    );
                }
            }
        }

        return back()->with('success', 'Dates blocked successfully.');
    }

    /**
     * Unblock specific dates
     */
    public function unblockDates(Request $request): RedirectResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'date' => 'required|date',
            'time_period' => 'nullable|in:morning,afternoon',
        ]);

        $query = Availability::where('user_id', $user->id)
            ->where('specific_date', $validated['date'])
            ->where('is_available', false);

        if ($validated['time_period']) {
            $query->where('time_period', $validated['time_period']);
        }

        $query->delete();

        return back()->with('success', 'Date unblocked successfully.');
    }

    /**
     * Get available slots for a date (API endpoint)
     */
    public function getAvailableSlots(Request $request, $instructorId): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date',
        ]);

        $date = $validated['date'];
        $dayOfWeek = date('w', strtotime($date));

        // Check for specific date blocks first
        $specificBlocks = Availability::where('user_id', $instructorId)
            ->where('specific_date', $date)
            ->where('is_available', false)
            ->pluck('time_period')
            ->toArray();

        // Get recurring availability
        $recurringAvailabilities = Availability::where('user_id', $instructorId)
            ->where('day_of_week', $dayOfWeek)
            ->whereNull('specific_date')
            ->where('is_available', true)
            ->pluck('time_period')
            ->toArray();

        // Calculate available slots
        $allPeriods = ['morning', 'afternoon'];
        $availableSlots = [];

        foreach ($allPeriods as $period) {
            // Available if recurring is set AND not specifically blocked
            $isAvailable = in_array($period, $recurringAvailabilities) 
                && !in_array($period, $specificBlocks);
            
            $availableSlots[$period] = $isAvailable;
        }

        return response()->json([
            'date' => $date,
            'available_slots' => $availableSlots,
        ]);
    }

    /**
     * Toggle instructor status (Vacation Mode)
     */
    public function toggleStatus(): RedirectResponse
    {
        $user = auth()->user();
        $profile = $user->instructorProfile;

        if ($profile->status === \App\Enums\InstructorStatus::Active) {
            $profile->update(['status' => \App\Enums\InstructorStatus::Inactive]);
            $message = 'Vacation Mode enabled. You are now hidden from search.';
        } elseif ($profile->status === \App\Enums\InstructorStatus::Inactive) {
            $profile->update(['status' => \App\Enums\InstructorStatus::Active]);
            $message = 'Vacation Mode disabled. You are now visible to tourists!';
        } else {
            return back()->with('error', 'You cannot toggle status while suspended or pending verification.');
        }

        return back()->with('success', $message);
    }
}