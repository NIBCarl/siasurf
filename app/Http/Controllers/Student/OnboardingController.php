<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Services\SkillAssessmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return Inertia::render('Student/Onboarding', [
            'profile' => [
                'skill_level' => $profile?->skill_level ?? 'beginner',
                'skill_assessed_at' => $profile?->skill_assessed_at?->toIso8601String(),
                'is_first_time' => (bool) ($profile?->is_first_time ?? true),
                'phone' => $user->phone,
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
        $validated = $request->validate([
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
            'answers.0.required' => 'Please answer the water safety question before continuing.',
            'confirmed.accepted' => 'Please confirm that your answers are accurate.',
        ]);

        $assessment = $assessmentService->recordForStudent($request->user(), $validated);
        $intended = $this->safeIntended($request->input('intended'));
        $params = $intended !== null ? ['intended' => $intended] : [];

        return redirect()
            ->route('student.onboarding', $params)
            ->with('success', 'Your assessed surf level is '.ucfirst($assessment->assessed_level).'. Complete your booking details below whenever you’re ready.');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]+$/'],
            'height' => 'nullable|numeric|min:50|max:250',
            'weight' => 'nullable|numeric|min:10|max:200',
            'has_board' => 'required|boolean',
            // Required only when they do not bring their own board, matching
            // what the booking form enforces.
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:50',
        ], [
            'phone.regex' => 'Enter a valid phone number using digits, spaces, +, -, or parentheses.',
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

        $profile->fill([
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
