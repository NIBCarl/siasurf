<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One-time capture of the details the booking form asks for.
 *
 * The prompt is skippable by design: a student may want to browse instructors
 * before typing their weight, and blocking that turns a nice-to-have into a
 * wall. Everything here is optional except the fields the booking form cannot
 * work without, and even those are only enforced when the form is submitted.
 */
class OnboardingController extends Controller
{
    public function edit(): Response
    {
        $user = auth()->user();
        $profile = $user->studentProfile;

        return Inertia::render('Student/Onboarding', [
            'profile' => [
                'skill_level' => $profile?->skill_level ?? 'beginner',
                'date_of_birth' => $profile?->date_of_birth?->format('Y-m-d'),
                'age' => $profile?->age,
                'height' => $profile?->height !== null ? (float) $profile->height : null,
                'weight' => $profile?->weight !== null ? (float) $profile->weight : null,
                'has_board' => (bool) ($profile?->has_board ?? false),
                'emergency_contact_name' => $profile?->emergency_contact_name,
                'emergency_contact_phone' => $profile?->emergency_contact_phone,
                'medical_info' => $profile?->medical_info,
            ],
            // Where to send them once they are done, so the prompt can be shown
            // mid-flow (for example when a booking form is opened first).
            'intended' => request('intended'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // Age is capped at 100 so a typo'd birth year cannot produce a
            // nonsensical age that later confuses the safety checks.
            'date_of_birth' => [
                'nullable',
                'date',
                'before:today',
                function ($attribute, $value, $fail) {
                    if ($value === null) {
                        return;
                    }

                    $age = Carbon::parse($value)->age;

                    if ($age < 5) {
                        $fail('Surfing lessons start at age 5.');
                    } elseif ($age > 100) {
                        $fail('Please check the date of birth — that would make you over 100.');
                    }
                },
            ],
            'height' => 'nullable|numeric|min:50|max:250',
            'weight' => 'nullable|numeric|min:10|max:200',
            'has_board' => 'required|boolean',
            // Required only when they do not bring their own board, matching
            // what the booking form enforces.
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:50',
            'medical_info' => 'nullable|string|max:1000',
        ], [
            'date_of_birth.date' => 'Enter your date of birth as a valid date.',
            'date_of_birth.before' => 'Your date of birth must be in the past.',
            'height.numeric' => 'Enter your height in centimetres, for example 175.',
            'weight.numeric' => 'Enter your weight in kilograms, for example 70.',
        ]);

        $user = auth()->user();

        $profile = $user->studentProfile()->firstOrNew(['user_id' => $user->id]);

        $profile->fill([
            'date_of_birth' => $validated['date_of_birth'] ?? null,
            'height' => $validated['height'] ?? null,
            'weight' => $validated['weight'] ?? null,
            'has_board' => (bool) $validated['has_board'],
            'emergency_contact_name' => $validated['emergency_contact_name'] ?? null,
            'emergency_contact_phone' => $validated['emergency_contact_phone'] ?? null,
            'medical_info' => $validated['medical_info'] ?? null,
        ]);

        // A student who brings their own board is never asked for height and
        // weight, so storing nulls for them is correct rather than incomplete.
        $profile->skill_level ??= 'beginner';
        $profile->save();

        $profile->markOnboarded();

        $intended = $this->safeIntended($request->input('intended'));

        return redirect($intended ?? route('student.dashboard'))
            ->with('success', 'Your details are saved. Bookings will use them automatically.');
    }

    /**
     * Skipping is recorded so the prompt appears once and then stops nagging.
     * It stays reachable from the profile page.
     */
    public function skip(Request $request): RedirectResponse
    {
        $user = auth()->user();
        $profile = $user->studentProfile()->firstOrNew(['user_id' => $user->id]);
        $profile->skill_level ??= 'beginner';
        $profile->save();

        $profile->markOnboardingSkipped();

        return redirect()->route('student.dashboard');
    }

    /**
     * Only ever redirect to a local path: an `intended` value taken straight
     * from the query string would otherwise be an open redirect.
     */
    private function safeIntended(mixed $intended): ?string
    {
        if (! is_string($intended) || $intended === '') {
            return null;
        }

        if (! str_starts_with($intended, '/') || str_starts_with($intended, '//')) {
            return null;
        }

        return $intended;
    }
}
