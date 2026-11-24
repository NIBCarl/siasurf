<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\SurfSpot;
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
        $profile = $user->instructorProfile;

        abort_if($profile === null, 404);

        $profile->load('surfSpots');

        $averageRating = $user->reviewsAsInstructor()->visible()->avg('rating');

        return Inertia::render('Instructor/Profile/Edit', [
            'availableSurfSpots' => SurfSpot::activeForInstructorLevel($profile->level)
                ->get()
                ->map(fn (SurfSpot $spot): array => $spot->toMapPayload())
                ->values()
                ->all(),
            'selectedSurfSpotIds' => $profile->activeAllowedSurfSpots()
                ->pluck('id')
                ->values()
                ->all(),
            'offeredServices' => $profile->offeredServiceValues(),
            'allowedServices' => $profile->level->allowedServiceValues(),
            'surfZoneRule' => $profile->level->surfZoneRuleLabel(),
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
     * Update the instructor's public profile, surf zones, lesson types, and rates.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $profile = $user->instructorProfile;

        abort_if($profile === null, 404);

        $level = $profile->level->value;
        $hasOfferedServices = $request->has('offered_services');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'bio' => 'nullable|string|max:1000',
            'birthday' => ['nullable', 'date', 'before_or_equal:'.now()->subYears(18)->toDateString()],
            ...PricingService::offeringValidationRules($level, $request->input('offered_services'), $hasOfferedServices),
            ...SurfSpot::instructorSelectionValidationRules($level, requireWhenPresent: true),
            'area' => 'nullable|string|max:100',
            'meeting_point' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:9.5,10.2',
            'longitude' => 'nullable|numeric|between:125.8,126.5',
        ], [
            ...PricingService::offeringValidationMessages($level),
            'birthday.before_or_equal' => 'Instructors must be at least 18 years old.',
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
        $offerings = PricingService::normalizeOfferingsForLevel($level, $validated, $hasOfferedServices, $profile);

        $attributes = [
            ...$offerings,
        ];

        foreach (['bio', 'birthday', 'area', 'meeting_point'] as $field) {
            if (array_key_exists($field, $validated)) {
                $attributes[$field] = $validated[$field];
            }
        }

        if (array_key_exists('latitude', $validated) || array_key_exists('longitude', $validated)) {
            $attributes['latitude'] = $validated['latitude'] ?? null;
            $attributes['longitude'] = $validated['longitude'] ?? null;
        }

        $profile->update($attributes);

        if (array_key_exists('surf_spot_ids', $validated)) {
            $profile->syncSurfSpotsAndLocation(
                $validated['surf_spot_ids'] ?? [],
                syncCoordinates: ! $request->filled('latitude') && ! $request->filled('longitude'),
                submittedArea: $validated['area'] ?? null,
            );
        }

        return back()->with('success', 'Profile, surf zones, and lesson rates updated.');
    }
}
