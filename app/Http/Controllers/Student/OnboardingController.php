<?php

namespace App\Http\Controllers\Student;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\SkillAssessmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One-time capture of the skill pre-assessment and booking prefill details.
 *
 * The prompt is skippable by design: a student who skips remains safely at
 * `beginner` until they complete the pre-assessment. Once submitted, the
 * initial pre-assessment locks and future level progression happens through
 * instructor-recommended skill upgrades.
 */
class OnboardingController extends Controller
{
    public function edit(SkillAssessmentService $assessmentService): Response
    {
        $user = auth()->user();
        $profile = $user->studentProfile;
        $latestAssessment = $user->latestSkillAssessment;
        $dob = $profile?->birthday?->format('Y-m-d');

        return Inertia::render('Student/Onboarding', [
            'profile' => [
                'skill_level' => $profile?->skill_level ?? 'beginner',
                'skill_assessed_at' => $profile?->skill_assessed_at?->toIso8601String(),
                'is_first_time' => (bool) ($profile?->is_first_time ?? true),
                'phone' => $user->phone,
                'date_of_birth' => $dob,
                'country' => $profile?->country,
                'age' => $profile?->age,
                'height' => $profile?->height !== null ? (float) $profile->height : null,
                'weight' => $profile?->weight !== null ? (float) $profile->weight : null,
                'has_board' => (bool) ($profile?->has_board ?? false),
                'emergency_contact_name' => $profile?->emergency_contact_name,
                'emergency_contact_phone' => $profile?->emergency_contact_phone,
            ],
            'assessmentDefinition' => $assessmentService->publicDefinition(),
            'latestAssessment' => $latestAssessment
                ? $assessmentService->formatForDisplay($latestAssessment)
                : null,
            // Where to send them once they are done, so the prompt can be shown
            // mid-flow (for example when a booking form is opened first).
            'intended' => request('intended'),
        ]);
    }

    public function storeAssessment(Request $request, SkillAssessmentService $assessmentService): RedirectResponse
    {
        $user = $request->user();
        $hasSavedBirthday = $user?->studentProfile?->birthday !== null;

        $validated = $request->validate([
            'date_of_birth' => [
                Rule::requiredIf(! $hasSavedBirthday),
                'nullable',
                'date',
                'before:today',
                function ($attribute, $value, $fail) {
                    if ($value === null || $value === '') {
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
            'can_swim_deep_water' => ['required', 'boolean'],
            'is_first_time' => ['required', 'boolean'],
            'skills' => ['nullable', 'array'],
            'skills.basic' => ['nullable', 'array'],
            'skills.basic.*' => ['integer', 'min:0'],
            'skills.intermediate' => ['nullable', 'array'],
            'skills.intermediate.*' => ['integer', 'min:0'],
            'skills.advanced' => ['nullable', 'array'],
            'skills.advanced.*' => ['integer', 'min:0'],
            'answers' => ['required', 'array'],
            'answers.0' => ['required', 'integer', 'between:0,3'],
            'answers.1' => ['nullable', 'integer', 'between:0,3'],
            'answers.2' => ['nullable', 'integer', 'between:0,3'],
            'answers.3' => ['nullable', 'integer', 'between:0,3'],
            'confirmed' => ['accepted'],
            'intended' => ['nullable', 'string'],
        ], [
            'date_of_birth.required' => 'Please enter your date of birth so we can verify age eligibility.',
            'date_of_birth.date' => 'Enter your date of birth as a valid date.',
            'date_of_birth.before' => 'Your date of birth must be in the past.',
            'answers.0.required' => 'Please answer the water safety question before continuing.',
            'confirmed.accepted' => 'Please confirm that your answers are accurate.',
        ]);

        if ($user->isStudent()) {
            $hasActiveBooking = Booking::where('student_id', $user->id)
                ->whereIn('status', array_map(fn (BookingStatus $s) => $s->value, BookingStatus::slotHolding()))
                ->exists();

            if ($hasActiveBooking) {
                return redirect()->back()
                    ->with('error', 'You cannot change your skill assessment while you have an active booking.');
            }
        }

        $birthday = ! empty($validated['date_of_birth']) ? $validated['date_of_birth'] : null;
        $assessment = $assessmentService->recordForStudent($user, $validated, $birthday);
        $intended = $this->safeIntended($request->input('intended'));
        $params = $intended !== null ? ['intended' => $intended] : [];

        $effectiveAge = $user->studentProfile?->fresh()?->age;
        $levelLabel = ucfirst($assessment->assessed_level);

        $message = "Your assessed surf level is {$levelLabel}. Complete your booking details below whenever you’re ready.";
        if ($effectiveAge !== null && $effectiveAge < 18) {
            $message = "Your assessed surf level is {$levelLabel}. Because you are under 18, parent or guardian consent will be required in the waiver before booking"
                .($effectiveAge <= 12 ? ' (and ages 5–12 require a Level 3 instructor).' : '.');
        }

        return redirect()
            ->route('student.onboarding', $params)
            ->with('success', $message);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]+$/'],
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
            'country' => ['nullable', 'string', 'max:100'],
            'height' => 'nullable|numeric|min:50|max:250',
            'weight' => 'nullable|numeric|min:10|max:200',
            'has_board' => 'required|boolean',
            // Required only when they do not bring their own board, matching
            // what the booking form enforces.
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:50',
        ], [
            'phone.regex' => 'Enter a valid phone number using digits, spaces, +, -, or parentheses.',
            'date_of_birth.date' => 'Enter your date of birth as a valid date.',
            'date_of_birth.before' => 'Your date of birth must be in the past.',
            'height.numeric' => 'Enter your height in centimetres, for example 175.',
            'weight.numeric' => 'Enter your weight in kilograms, for example 70.',
        ]);

        $user = auth()->user();

        if (array_key_exists('phone', $validated)) {
            $phone = is_string($validated['phone']) ? trim($validated['phone']) : null;
            $user->phone = $phone !== '' ? $phone : null;
            $user->save();
        }

        $profile = $user->studentProfile()->firstOrNew(['user_id' => $user->id]);

        $dob = $validated['date_of_birth'] ?? null;
        $currentDob = $profile->birthday?->format('Y-m-d');

        if ($dob !== $currentDob && $user->isStudent()) {
            $hasActiveBooking = Booking::where('student_id', $user->id)
                ->whereIn('status', array_map(fn (BookingStatus $s) => $s->value, BookingStatus::slotHolding()))
                ->exists();

            if ($hasActiveBooking) {
                throw ValidationException::withMessages([
                    'date_of_birth' => 'You cannot change your date of birth while you have an active booking.',
                ]);
            }
        }
        $country = isset($validated['country']) && trim((string) $validated['country']) !== ''
            ? trim((string) $validated['country'])
            : null;

        $profile->fill([
            'birthday' => $dob,
            'country' => $country,
            'height' => $validated['height'] ?? null,
            'weight' => $validated['weight'] ?? null,
            'has_board' => (bool) $validated['has_board'],
            'emergency_contact_name' => $validated['emergency_contact_name'] ?? null,
            'emergency_contact_phone' => $validated['emergency_contact_phone'] ?? null,
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
