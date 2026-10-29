<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Services\PricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the instructor's public profile editor.
     *
     * Real numbers are passed in so the page never shows invented ratings,
     * lesson counts, or a hardcoded certificate list.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        abort_if($user->instructorProfile === null, 404);

        $averageRating = $user->reviewsAsInstructor()->visible()->avg('rating');

        return Inertia::render('Instructor/Profile/Edit', [
            'stats' => [
                'average_rating' => $averageRating !== null ? round((float) $averageRating, 1) : null,
                'total_reviews' => $user->reviewsAsInstructor()->visible()->count(),
                'completed_lessons' => $user->bookingsAsInstructor()
                    ->where('status', BookingStatus::Completed)
                    ->count(),
            ],
            'certificates' => $user->certificates()
                ->latest()
                ->get()
                ->map(fn (Certificate $certificate): array => [
                    'id' => $certificate->id,
                    'type' => $certificate->type->value,
                    'type_label' => $certificate->type->label(),
                    'status' => $certificate->status->value,
                    'status_label' => $certificate->status->label(),
                    'expires_at' => $certificate->expires_at?->toDateString(),
                ])
                ->values(),
        ]);
    }

    /**
     * Update the instructor's public profile and directory location.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $profile = $user->instructorProfile;

        abort_if($profile === null, 404);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'bio' => 'nullable|string|max:1000',
            'rate_per_hour' => 'required|numeric|'.PricingService::rateRangeRule(),
            'area' => 'nullable|string|max:100',
            'meeting_point' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:9.5,10.2',
            'longitude' => 'nullable|numeric|between:125.8,126.5',
        ], [
            'rate_per_hour.min' => 'The base rate must be at least ₱'.number_format(PricingService::minRatePerHour()).' per hour.',
            'rate_per_hour.max' => 'The standard rate cannot exceed ₱'.number_format(PricingService::maxRatePerHour()).' per hour.',
            'latitude.between' => 'Choose a latitude inside Siargao Island.',
            'longitude.between' => 'Choose a longitude inside Siargao Island.',
        ]);

        if (($validated['latitude'] ?? null) !== null && ($validated['longitude'] ?? null) === null) {
            return back()->withErrors(['longitude' => 'Add a longitude as well as a latitude.'])->withInput();
        }

        if (($validated['longitude'] ?? null) !== null && ($validated['latitude'] ?? null) === null) {
            return back()->withErrors(['latitude' => 'Add a latitude as well as a longitude.'])->withInput();
        }

        $user->update(['name' => $validated['name']]);

        $profile->update([
            'bio' => $validated['bio'] ?? null,
            'rate_per_hour' => $validated['rate_per_hour'],
            'area' => $validated['area'] ?? null,
            'meeting_point' => $validated['meeting_point'] ?? null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
        ]);

        return back()->with('success', 'Profile updated. Your directory pin is live when you add coordinates.');
    }
}
