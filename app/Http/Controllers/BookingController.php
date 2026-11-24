<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Enums\CancellationReason;
use App\Enums\InstructorLevel;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\SessionType;
use App\Enums\SkillLevel;
use App\Events\BookingCreated;
use App\Exceptions\BookingChangeException;
use App\Exceptions\BookingRequestException;
use App\Exceptions\SafetyViolationException;
use App\Jobs\ExpireBookingAcceptanceJob;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\SurfSpot;
use App\Models\User;
use App\Notifications\BookingRequestedNotification;
use App\Services\AvailabilityService;
use App\Services\BookingAcceptanceService;
use App\Services\BookingCancellationService;
use App\Services\BookingChangeService;
use App\Services\BookingEventService;
use App\Services\BookingRequestGuard;
use App\Services\PricingService;
use App\Services\RefundCalculatorService;
use App\Services\SafetyService;
use App\Services\StormglassService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    protected SafetyService $safetyService;

    protected PricingService $pricingService;

    protected StormglassService $stormglassService;

    protected BookingRequestGuard $requestGuard;

    protected BookingAcceptanceService $acceptanceService;

    protected AvailabilityService $availabilityService;

    public function __construct(
        SafetyService $safetyService,
        PricingService $pricingService,
        StormglassService $stormglassService,
        BookingRequestGuard $requestGuard,
        BookingAcceptanceService $acceptanceService,
        AvailabilityService $availabilityService
    ) {
        $this->safetyService = $safetyService;
        $this->pricingService = $pricingService;
        $this->stormglassService = $stormglassService;
        $this->requestGuard = $requestGuard;
        $this->acceptanceService = $acceptanceService;
        $this->availabilityService = $availabilityService;
    }

    /**
     * Display the booking creation form (Step 1: Lesson details)
     */
    public function create(Request $request, User $instructor): Response|RedirectResponse
    {
        Gate::authorize('create', Booking::class);

        if (! $instructor->isBookable() || ($instructor->certificates()->exists() && ! $instructor->hasVerifiedCertificate())) {
            return redirect()->route('instructors.search')->with('error', 'This instructor is currently not available for bookings.');
        }

        // If the student navigated back from the Waiver/Payment step, release
        // their unfinished checkout hold immediately so the slot is freed.
        $this->availabilityService->releaseExpiredCheckoutHolds($instructor->id);
        $this->availabilityService->releaseStudentCheckoutHolds(auth()->id(), $instructor->id);

        // Skill-level matching also applies to the booking form itself: a
        // beginner-only instructor must not be reachable by an advanced
        // student through a direct URL.
        $viewerSkill = auth()->user()->studentProfile?->skill_level;
        $viewerSkill = $viewerSkill instanceof SkillLevel
            ? $viewerSkill
            : SkillLevel::tryFrom((string) $viewerSkill);

        abort_if(
            $viewerSkill !== null
            && !$viewerSkill->canBeTaughtBy($instructor->instructorProfile->level->value),
            404
        );

        $instructor->load(['instructorProfile.surfSpots', 'availabilities']);

        $profile = $instructor->instructorProfile;

        // Get available surf spots based on BOTH the instructor's selected surf zones
        // and the student's own level. Outer/inner sections are separate records,
        // ordered by entry difficulty then name.
        $studentSkill = $viewerSkill ?? SkillLevel::Beginner;
        $instructorLevelValue = $profile->level->value;

        // Get safety rules applicable to this instructor + student's skill level
        $safetyRules = $this->safetyService->getApplicableRules(
            $instructorLevelValue,
            $studentSkill->value
        );

        $requiredService = SessionType::forSkill($studentSkill);
        $offersRequiredService = $profile->offersService($requiredService);
        $serviceOptions = $offersRequiredService
            ? [[
                'type' => $requiredService->value,
                'label' => $requiredService->label(),
                'description' => $requiredService->description(),
                'rate' => $profile->rateForService($requiredService),
                'recommended' => true,
            ]]
            : [];

        $surfSpots = $profile->bookableSurfSpotsFor($studentSkill)
            ->map(fn(SurfSpot $spot): array => $spot->toMapPayload())
            ->values()
            ->all();

        $studentUser = auth()->user();
        $studentProfile = $studentUser->studentProfile;

        $tz = config('app.timezone', 'Asia/Manila');
        $initialDate = null;
        $rawDate = $request->query('date');
        if (is_string($rawDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
            try {
                $parsedDate = Carbon::createFromFormat('Y-m-d', $rawDate, $tz);
                if ($parsedDate && $parsedDate->format('Y-m-d') === $rawDate && $parsedDate->greaterThanOrEqualTo(today($tz))) {
                    $initialDate = $rawDate;
                }
            } catch (\Throwable) {
                $initialDate = null;
            }
        }

        $initialStartTime = null;
        $rawStart = $request->query('start_time');
        if ($initialDate !== null && is_string($rawStart) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $rawStart)) {
            $initialStartTime = $rawStart;
        }

        return Inertia::render('Booking/Create', [
            'instructor' => $instructor,
            'surfSpots' => $surfSpots,
            'instructorHasCustomSpots' => $profile->hasConfiguredSurfSpots(),
            'safetyRules' => $safetyRules,
            'serviceOptions' => $serviceOptions,
            'defaultSessionType' => $offersRequiredService ? $requiredService->value : '',
            'minimumDurationMinutes' => $requiredService->minimumMinutes(),
            'serviceFeePercent' => PricingService::serviceFeePercent(),
            // Explains why some breaks are not in the list.
            'surfSpotRule' => $studentSkill->surfSpotRuleLabel(),
            'allowedSurfSpotDifficulties' => $studentSkill->allowedSurfSpotDifficulties(),
            'tideData' => $this->stormglassService->getUpcomingTides(7),
            // Standard 2-hour default (Teaching starts at 2h recommended with 1h option;
            // Guiding & Coaching require at least 2h).
            'defaultDurationMinutes' => $requiredService->defaultMinutes(),
            // The account holder's level is the source of truth (set at signup
            // and maintained by the skill-upgrade flow) - not re-asked here.
            'studentSkillLevel' => $studentProfile?->skill_level ?? SkillLevel::Beginner->value,
            'studentName' => $studentUser->name,
            'studentProfile' => [
                'email' => $studentUser->email,
                'birthday' => $studentProfile?->birthday?->format('Y-m-d'),
                'age' => $studentProfile?->age,
                'country' => $studentProfile?->country,
            ],
            // Details captured during onboarding, so the student is not asked
            // for their height and weight on every booking.
            'prefill' => $studentProfile?->bookingPrefill() ?? [],
            'initialDate' => $initialDate,
            'initialStartTime' => $initialStartTime,
            'instructorSessionWarning' => $this->availabilityService->activeSessionNotice($instructor),
            'step' => 1,
        ]);
    }

    /**
     * Store booking details and validate safety rules (Step 1 submit)
     */
    public function storeDetails(Request $request, User $instructor): Response|RedirectResponse
    {
        Gate::authorize('create', Booking::class);

        if (! $instructor->isBookable() || ($instructor->certificates()->exists() && ! $instructor->hasVerifiedCertificate())) {
            return redirect()->route('instructors.search')->with('error', 'This instructor is currently not available for bookings.');
        }

        $validated = $request->validate([
            'date' => 'required|date|after_or_equal:today',
            'start_time' => [
                'required',
                'date_format:H:i',
                function ($attribute, $value, $fail) use ($request) {
                    $dateTime = Carbon::parse($request->date . ' ' . $value);
                    if ($dateTime->isPast()) {
                        $fail('The ' . $attribute . ' must be in the future.');
                    }
                },
            ],
            // The student now chooses both ends of the lesson. The server still
            // re-validates the range against the instructor's schedule and the
            // slot's remaining student capacity. Older clients that only send a
            // start time fall back to the instructor's standard lesson length.
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            // Service type (teaching, guiding, coaching). Defaults cleanly to
            // the recommended service for this student + instructor pair.
            'session_type' => 'nullable|in:teaching,guiding,coaching',
            // Primary skill is derived from the profile; still accepted for
            // backwards compatibility with older clients.
            'skill_level' => 'nullable|in:beginner,intermediate,advanced',
            'student_age' => 'required|integer|min:5|max:100',
            // Height and weight exist only to recommend a board, so they are
            // not asked for students who bring their own board.
            'height' => [
                'nullable',
                'numeric',
                'min:50',
                'max:250',
                Rule::requiredIf(fn() => empty($request->input('participants'))
                    && !$request->boolean('has_board')),
            ],
            'weight' => [
                'nullable',
                'numeric',
                'min:10',
                'max:200',
                Rule::requiredIf(fn() => empty($request->input('participants'))
                    && !$request->boolean('has_board')),
            ],
            'student_count' => 'nullable|integer|min:1|max:1',
            'surf_spot_id' => 'required|exists:surf_spots,id',
            'has_board' => 'required|boolean',
            'needs_hotel_pickup' => 'nullable|boolean',
            'hotel_name' => 'nullable|required_if:needs_hotel_pickup,true,1|string|max:255',
            'student_birthday' => ['nullable', 'date', 'after:1920-01-01', 'before_or_equal:' . now()->subYears(5)->toDateString()],
            'student_country' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',

            // Single registered student per booking (1 account = 1 seat).
            'participants' => 'nullable|array|max:1',
            'companions' => 'nullable|array|max:0',
            'participants.*.full_name' => 'required|string|max:255',
            'participants.*.birthday' => ['nullable', 'date', 'after:1920-01-01', 'before_or_equal:' . now()->subYears(5)->toDateString()],
            'participants.*.age' => 'required|integer|min:5|max:100',
            'participants.*.email' => 'nullable|email|max:255',
            'participants.*.country' => 'nullable|string|max:100',
            'participants.*.height' => 'nullable|numeric|min:50|max:250',
            'participants.*.weight' => 'nullable|numeric|min:10|max:200',
            'participants.*.skill_level' => 'required|in:beginner,intermediate,advanced',
            'participants.*.has_board' => 'required|boolean',
            'participants.*.emergency_contact_name' => 'nullable|string|max:255',
            'participants.*.emergency_contact_phone' => 'nullable|string|max:50',
            'participants.*.medical_info' => 'nullable|string|max:1000',
            'participants.*.guardian_name' => 'nullable|string|max:255',
            'participants.*.guardian_phone' => 'nullable|string|max:50',
        ], [
            'end_time.after' => 'The end time must be after the start time.',
            'height.required' => 'Height is needed to recommend a board. Tick “I have my own board” if you do not need one.',
            'weight.required' => 'Weight is needed to recommend a board. Tick “I have my own board” if you do not need one.',
            'hotel_name.required_if' => 'Please enter the hotel or accommodation name where the instructor will pick you up.',
            'student_count.max' => 'Each booking reserves 1 seat for the signed-in student. For group sessions, each surfer must sign in with their own account and book an open seat in the same time slot.',
            'participants.max' => 'Each booking reserves 1 seat for the signed-in student. For group sessions, each surfer must sign in with their own account and book an open seat in the same time slot.',
            'companions.max' => 'Each booking reserves 1 seat for the signed-in student. For group sessions, each surfer must sign in with their own account and book an open seat in the same time slot.',
        ]);

        $rawParticipants = $request->input('participants');

        // Per-participant board-size fields follow the same rule: only required
        // when that surfer does not have their own board.
        foreach (($rawParticipants ?? []) as $index => $participant) {
            $hasBoard = filter_var($participant['has_board'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if (!$hasBoard && (blank($participant['height'] ?? null) || blank($participant['weight'] ?? null))) {
                throw ValidationException::withMessages([
                    "participants.{$index}.height" => 'Height and weight are required when this surfer does not have a board.',
                ]);
            }

            if (! empty($participant['birthday'])) {
                $derivedAge = Carbon::parse($participant['birthday'])->age;
                if ($derivedAge < 5 || $derivedAge > 100) {
                    throw ValidationException::withMessages([
                        "participants.{$index}.birthday" => 'Surfers must be between 5 and 100 years old.',
                    ]);
                }
            }
        }

        // Determine the time period from the chosen start, and derive the
        // lesson length from the two ends the student picked. When an older
        // client omits end_time, clamp the fallback up to the service minimum.
        $profileSkillRaw = auth()->user()->studentProfile?->skill_level;
        $fallbackSkillEnum = $profileSkillRaw instanceof SkillLevel
            ? $profileSkillRaw
            : (SkillLevel::tryFrom((string) ($profileSkillRaw ?: ($validated['skill_level'] ?? ''))) ?? SkillLevel::Beginner);
        $fallbackService = SessionType::forSkill($fallbackSkillEnum);

        $startTime = Carbon::parse($validated['start_time']);
        $validated['time_period'] = $startTime->hour < 12 ? 'morning' : 'afternoon';

        $endTime = !empty($validated['end_time'])
            ? Carbon::parse($validated['end_time'])
            : $startTime->copy()->addMinutes(
                max(
                    $this->availabilityService->sessionMinutesFor(
                        $instructor->id,
                        $validated['date'],
                        $validated['time_period']
                    ),
                    $fallbackService->minimumMinutes()
                )
            );

        if ($endTime->lessThanOrEqualTo($startTime)) {
            return back()->withErrors(['end_time' => 'The end time must be after the start time.']);
        }

        $validated['end_time'] = $endTime->format('H:i');
        $durationMinutes = (int) $startTime->diffInMinutes($endTime);
        $validated['duration_hours'] = (int) round($durationMinutes / 60);

        // Resolve who is surfing. The account holder's skill level always comes
        // from their profile (the source of truth) - never from the form.
        $studentUser = auth()->user();
        $studentProfile = $studentUser->studentProfile;

        // If a legacy student account supplied missing birthday/country on the
        // booking form, persist them onto their student profile so future
        // waivers are prefilled automatically.
        if ($studentUser->isStudent() && (!empty($validated['student_birthday']) || !empty($validated['student_country']))) {
            $profileUpdates = [];
            if (empty($studentProfile?->birthday) && !empty($validated['student_birthday'])) {
                $profileUpdates['birthday'] = $validated['student_birthday'];
            }
            if (empty($studentProfile?->country) && !empty($validated['student_country'])) {
                $profileUpdates['country'] = trim((string) $validated['student_country']);
            }
            if (!empty($profileUpdates)) {
                if ($studentProfile) {
                    $studentProfile->update($profileUpdates);
                    $studentProfile->refresh();
                } else {
                    $studentProfile = $studentUser->studentProfile()->create(array_merge([
                        'skill_level' => $validated['skill_level'] ?? SkillLevel::Beginner->value,
                        'is_first_time' => true,
                        'status' => 'active',
                    ], $profileUpdates));
                }
            }
        }

        $profileLevel = $studentProfile?->skill_level;
        $primarySkill = $profileLevel instanceof SkillLevel
            ? $profileLevel->value
            : ($profileLevel ?: ($validated['skill_level'] ?? SkillLevel::Beginner->value));

        $needsHotelPickup = (bool) ($validated['needs_hotel_pickup'] ?? false);
        $validated['needs_hotel_pickup'] = $needsHotelPickup;
        $validated['hotel_name'] = $needsHotelPickup && !empty($validated['hotel_name'])
            ? trim((string) $validated['hotel_name'])
            : null;

        // Height/weight may be intentionally absent when the student brings a
        // board, so normalise them before the row is written.
        $validated['height'] = $validated['height'] ?? null;
        $validated['weight'] = $validated['weight'] ?? null;

        $primaryBirthday = $studentProfile?->birthday?->format('Y-m-d');
        $primaryCountry = $studentProfile?->country;
        $primaryEmail = $studentUser->email;

        $participantsInput = $validated['participants'] ?? null;
        $hasExplicitParticipants = !empty($participantsInput);

        $validated['student_count'] = 1;

        if ($hasExplicitParticipants) {
            // Index 0 is the sole account holder; override any client-supplied level.
            $participantsInput = [array_merge($participantsInput[0], [
                'full_name' => $studentUser->name ?: ($participantsInput[0]['full_name'] ?? ''),
                'skill_level' => $primarySkill,
                'birthday' => $primaryBirthday ?? ($participantsInput[0]['birthday'] ?? null),
                'email' => $primaryEmail,
                'country' => $primaryCountry ?? ($participantsInput[0]['country'] ?? null),
            ])];

            if ($studentProfile?->age !== null) {
                $participantsInput[0]['age'] = $studentProfile->age;
            } elseif (! empty($participantsInput[0]['birthday'])) {
                $participantsInput[0]['age'] = Carbon::parse($participantsInput[0]['birthday'])->age;
            }

            $primary = $participantsInput[0];
            $validated['skill_level'] = $primarySkill;
            $validated['student_age'] = $primary['age'];
            $validated['height'] = $primary['height'] ?? null;
            $validated['weight'] = $primary['weight'] ?? null;
            $validated['has_board'] = (bool) ($primary['has_board'] ?? $validated['has_board']);
        } else {
            // Single-person payload: synthesise the primary participant.
            $primaryAge = $studentProfile?->birthday?->age ?? $studentProfile?->age ?? $validated['student_age'];
            $validated['student_age'] = $primaryAge;
            $participantsInput = [
                [
                    'full_name' => $studentUser->name,
                    'birthday' => $primaryBirthday,
                    'age' => $primaryAge,
                    'email' => $primaryEmail,
                    'country' => $primaryCountry,
                    'height' => $validated['height'] ?? null,
                    'weight' => $validated['weight'] ?? null,
                    'skill_level' => $primarySkill,
                    'has_board' => $validated['has_board'],
                ]
            ];
            $validated['skill_level'] = $primarySkill;
        }

        // Skill-level matching (BR-3.6): the directory already hides
        // instructors that cannot teach this student, and this guard makes sure
        // a hand-crafted request cannot bypass that rule either.
        $instructorLevelValue = $instructor->instructorProfile->level->value;
        $studentSkill = SkillLevel::tryFrom($primarySkill) ?? SkillLevel::Beginner;

        if (!$studentSkill->canBeTaughtBy($instructorLevelValue)) {
            return back()->with('error', $studentSkill->label()
                . ' lessons need a ' . $studentSkill->instructorLevelRangeLabel()
                . '. This instructor is Level ' . $instructorLevelValue . '.');
        }

        // Strict 1-to-1 service assignment from student skill level:
        // Beginner -> Teaching, Intermediate -> Guiding, Advanced -> Coaching.
        $profile = $instructor->instructorProfile->loadMissing('surfSpots');

        $requiredService = SessionType::forSkill($studentSkill);

        if (! empty($validated['session_type']) && $validated['session_type'] !== $requiredService->value) {
            return back()->with(
                'error',
                "Your {$studentSkill->label()} skill level is assigned to {$requiredService->label()} sessions only."
            );
        }

        $sessionType = $requiredService;

        if (! $profile->offersService($sessionType)) {
            return back()->with('error', "This instructor does not offer {$sessionType->label()} sessions.");
        }

        // Get surf spot for validation
        $surfSpot = SurfSpot::findOrFail($validated['surf_spot_id']);

        if (!$surfSpot->is_active) {
            return back()->with('error', 'The selected surf spot is currently unavailable. Please choose another surf spot.');
        }

        if (!$profile->teachesAtSurfSpot($surfSpot)) {
            return back()->with('error', "This instructor does not teach at {$surfSpot->name}. Please choose one of their available surf zones.");
        }

        // Check tide conditions for the selected date/time
        $tideWarning = $this->stormglassService->getTideWarning(
            $validated['date'],
            $validated['start_time'],
            $durationMinutes
        );

        // Safety validation: every booking represents 1 verified student account.
        try {
            $this->safetyService->validateParticipants(
                $participantsInput,
                $instructorLevelValue,
                $surfSpot->difficulty,
                auth()->id(),
                $sessionType
            );
        } catch (SafetyViolationException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Clean up any expired 15-minute checkout holds on this instructor and
        // any unfinished checkout hold belonging to this student before checking
        // availability or the open-request guard.
        $this->availabilityService->releaseExpiredCheckoutHolds($instructor->id);
        $this->availabilityService->releaseStudentCheckoutHolds(auth()->id(), $instructor->id);

        // FR 1.8 - respect the instructor's schedule, skill-dependent seat cap,
        // same-level/same-service shared-slot rule, and shared surf spot lock.
        try {
            $this->availabilityService->assertRangeIsBookable(
                $instructor->id,
                $validated['date'],
                $validated['start_time'],
                $validated['end_time'],
                $validated['student_count'],
                null,
                $studentSkill,
                $sessionType,
                $surfSpot->id
            );
        } catch (BookingRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        // Calculate pricing using the service provided (Teaching / Guiding / Coaching).
        $pricing = $this->pricingService->calculatePrice(
            $instructor->instructorProfile,
            $validated['student_count'],
            [],
            null,
            $validated['duration_hours'],
            $sessionType,
            $studentSkill
        );

        // Abuse guard: block a duplicate request for the same slot, and cap how
        // many unanswered requests one student can hold.
        try {
            $this->requestGuard->assertStudentMayRequest(auth()->user(), [
                'instructor_id' => $instructor->id,
                'date' => $validated['date'],
                'time_period' => $validated['time_period'],
            ]);
        } catch (BookingRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        // Create the booking and its participants atomically. The instructor
        // row is locked so two students cannot both pass the capacity check and
        // create overlapping requests in the same instant.
        try {
            $booking = DB::transaction(function () use ($validated, $instructor, $pricing, $participantsInput, $studentSkill, $sessionType, $surfSpot) {
                User::query()->whereKey($instructor->id)->lockForUpdate()->first();

                $this->availabilityService->releaseExpiredCheckoutHolds($instructor->id);
                $this->availabilityService->releaseStudentCheckoutHolds(auth()->id(), $instructor->id);

                // Re-check under the lock: the first transaction may have taken
                // the slot while this request was waiting.
                $this->availabilityService->assertRangeIsBookable(
                    $instructor->id,
                    $validated['date'],
                    $validated['start_time'],
                    $validated['end_time'],
                    $validated['student_count'],
                    null,
                    $studentSkill,
                    $sessionType,
                    $surfSpot->id
                );

                $this->requestGuard->assertStudentMayRequest(auth()->user(), [
                    'instructor_id' => $instructor->id,
                    'date' => $validated['date'],
                    'time_period' => $validated['time_period'],
                ]);

                $tz = config('app.timezone', 'Asia/Manila');
                $lessonDate = Carbon::parse($validated['date'], $tz)->startOfDay();
                $bookingDate = now($tz)->startOfDay();
                $leadDays = (int) round($bookingDate->diffInDays($lessonDate, false));
                $isLongTerm = $leadDays > (int) config('siasurf.refunds.long_term_threshold_days', 30);

                $booking = Booking::create([
                    'student_id' => auth()->id(),
                    'instructor_id' => $instructor->id,
                    'surf_spot_id' => $validated['surf_spot_id'],
                    'date' => $validated['date'],
                    'original_date' => $validated['date'],
                    'is_long_term' => $isLongTerm,
                    'time_period' => $validated['time_period'],
                    'start_time' => $validated['start_time'],
                    'end_time' => $validated['end_time'],
                    'duration_hours' => $validated['duration_hours'],
                    'skill_level' => $validated['skill_level'],
                    'session_type' => $sessionType->value,
                    'hourly_rate' => $pricing['base_rate'],
                    'lesson_price' => $pricing['lesson_price'],
                    'service_fee_percent' => $pricing['service_fee_percent'],
                    'service_fee_amount' => $pricing['service_fee_amount'],
                    'student_age' => $validated['student_age'],
                    'height' => $validated['height'],
                    'weight' => $validated['weight'],
                    'student_count' => $validated['student_count'],
                    'has_board' => $validated['has_board'],
                    'needs_hotel_pickup' => $validated['needs_hotel_pickup'],
                    'hotel_name' => $validated['hotel_name'],
                    'status' => BookingStatus::Reserved,
                    'total_amount' => $pricing['total'],
                    'payment_status' => PaymentStatus::Pending,
                    'notes' => $validated['notes'] ?? null,
                ]);

                foreach ($participantsInput as $index => $participant) {
                    $booking->participants()->create([
                        'role' => $index === 0
                            ? BookingParticipant::ROLE_PRIMARY
                            : BookingParticipant::ROLE_COMPANION,
                        'full_name' => $participant['full_name'] ?? null,
                        'birthday' => $participant['birthday'] ?? null,
                        'age' => $participant['age'] ?? null,
                        'email' => $participant['email'] ?? null,
                        'country' => $participant['country'] ?? null,
                        'height' => $participant['height'] ?? null,
                        'weight' => $participant['weight'] ?? null,
                        'skill_level' => $participant['skill_level'] ?? SkillLevel::Beginner->value,
                        'has_board' => (bool) ($participant['has_board'] ?? false),
                        'emergency_contact_name' => $participant['emergency_contact_name'] ?? null,
                        'emergency_contact_phone' => $participant['emergency_contact_phone'] ?? null,
                        'medical_info' => $participant['medical_info'] ?? null,
                        'guardian_name' => $participant['guardian_name'] ?? null,
                        'guardian_phone' => $participant['guardian_phone'] ?? null,
                    ]);
                }

                // Start the 15-minute checkout hold clock while the student
                // signs the waiver and chooses a payment method. The instructor
                // is only notified after storePayment() completes.
                $checkoutDeadline = $booking->checkoutDeadlineFrom();
                $booking->update([
                    'payment_deadline_at' => $checkoutDeadline,
                    'accept_deadline_at' => null,
                ]);

                app(BookingEventService::class)->recordCreated($booking, auth()->id());

                // Lock the slot immediately for all instructor levels so no
                // other student can overtake this slot during waiver signing.
                $this->availabilityService->lockIfSlotIsFull($booking);

                ExpireBookingAcceptanceJob::dispatch($booking)
                    ->delay($checkoutDeadline);

                return $booking;
            });
        } catch (BookingRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $redirect = redirect()->route('bookings.waiver', $booking->id)
            ->with('success', 'Details saved. Please sign the waiver.');

        if ($tideWarning) {
            $redirect = $redirect->with('tideWarning', $tideWarning);
        }

        return $redirect;
    }

    /**
     * Display payment page (Step 3: Payment)
     */
    public function payment(Booking $booking): Response|RedirectResponse
    {
        Gate::authorize('view', $booking);

        if ($booking->checkoutHoldExpired()) {
            $booking->expireAcceptance('Waiver and checkout hold expired after ' . Booking::CHECKOUT_HOLD_MINUTES . ' minutes.');
            $this->availabilityService->releaseForBooking($booking);

            return redirect()->route('bookings.create', $booking->instructor_id)
                ->with('error', 'Your 15-minute reservation hold expired. Please select your time slot again.');
        }

        if ($booking->status === BookingStatus::AwaitingPayment) {
            return redirect()->route('payments.show', $booking)
                ->with('info', 'Your instructor accepted this booking. Complete the GCash payment from this page before the hold expires.');
        }

        if (!in_array($booking->status, [BookingStatus::Reserved, BookingStatus::Pending], true)) {
            return redirect()->route('student.bookings.show', $booking)
                ->with('info', 'This booking has already moved past the payment-method step.');
        }

        $booking->load(['instructor.instructorProfile', 'surfSpot']);

        return Inertia::render('Booking/Payment', [
            'booking' => $booking,
            'step' => 3,
            'holdRemainingSeconds' => $booking->getPaymentTimeRemaining(),
            'instructorSessionWarning' => $this->availabilityService->activeSessionNotice($booking->instructor ?? $booking->instructor_id),
        ]);
    }

    /**
     * Process payment (Step 3: Payment)
     */
    public function storePayment(Request $request, Booking $booking): RedirectResponse
    {
        // The student owns the booking; the locked status guards below prevent
        // this endpoint from regressing an accepted/confirmed booking or
        // racing with checkout hold expiry.
        Gate::authorize('view', $booking);

        $validated = $request->validate([
            'payment_method' => 'required|in:gcash,cash',
        ]);

        // Instant Book: a trusted instructor's calendar confirms immediately, so
        // the student skips the 2-hour acceptance wait entirely.
        $instantBook = (bool) $booking->instructor?->instructorProfile?->usesInstantBook();

        $outcome = DB::transaction(function () use ($booking, $validated, $instantBook): array {
            User::query()->whereKey($booking->instructor_id)->lockForUpdate()->first();
            $locked = Booking::with(['instructor.instructorProfile', 'payment'])
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->checkoutHoldExpired()) {
                $locked->expireAcceptance('Waiver and checkout hold expired after '.Booking::CHECKOUT_HOLD_MINUTES.' minutes.');
                $this->availabilityService->releaseForBooking($locked);

                return ['result' => 'expired', 'booking' => $locked];
            }

            if ($locked->status === BookingStatus::AwaitingPayment) {
                return ['result' => 'awaiting_payment', 'booking' => $locked];
            }

            if (! in_array($locked->status, [BookingStatus::Reserved, BookingStatus::Pending], true)) {
                return ['result' => 'invalid_status', 'booking' => $locked];
            }

            // If still Reserved (e.g. racing near hold boundary), verify the slot
            // still has valid open capacity before admitting to Pending.
            if ($locked->status === BookingStatus::Reserved && $locked->start_time && $locked->end_time) {
                $skillEnum = $locked->skill_level instanceof SkillLevel
                    ? $locked->skill_level
                    : SkillLevel::tryFrom((string) $locked->skill_level);
                $serviceEnum = $locked->session_type instanceof SessionType
                    ? $locked->session_type
                    : SessionType::tryFrom((string) $locked->session_type);

                try {
                    $this->availabilityService->assertRangeIsBookable(
                        $locked->instructor_id,
                        $locked->date->format('Y-m-d'),
                        $locked->start_time->format('H:i'),
                        $locked->end_time->format('H:i'),
                        max(1, (int) $locked->student_count),
                        $locked->id,
                        $skillEnum,
                        $serviceEnum,
                        $locked->surf_spot_id ? (int) $locked->surf_spot_id : null
                    );
                } catch (BookingRequestException $exception) {
                    $locked->expireAcceptance($exception->getMessage());
                    $this->availabilityService->releaseForBooking($locked);

                    return ['result' => 'slot_conflict', 'message' => $exception->getMessage(), 'booking' => $locked];
                }
            }

            // Update booking status and transition from the 15-minute student
            // checkout hold to the 2-hour instructor acceptance window.
            $locked->update([
                'payment_status' => $validated['payment_method'] === 'cash'
                    ? PaymentStatus::PendingCash
                    : PaymentStatus::Pending,
                'status' => BookingStatus::Pending, // Status remains pending until confirmed or paid
                'payment_deadline_at' => null,
                // FR 1.9.3 - the instructor now has 2 hours to accept or decline.
                'accept_deadline_at' => $locked->acceptDeadlineFrom(),
            ]);

            // Create or update payment record
            $locked->payment()->updateOrCreate([], [
                'amount' => $locked->total_amount,
                'lesson_price' => $locked->effectiveLessonPrice(),
                'service_fee_amount' => $locked->effectiveServiceFeeAmount(),
                'payment_method' => $validated['payment_method'],
                'status' => $validated['payment_method'] === 'cash'
                    ? PaymentStatus::PendingCash
                    : PaymentStatus::Pending,
            ]);

            // Instant Book skips the request queue, so there is nothing to notify the
            // instructor about or to expire.
            if (! $instantBook) {
                DB::afterCommit(function () use ($locked) {
                    $fresh = $locked->fresh(['instructor', 'student', 'surfSpot']);
                    if (! $fresh) {
                        return;
                    }

                    try {
                        broadcast(new BookingCreated($fresh))->toOthers();
                    } catch (\Throwable $exception) {
                        Log::warning('Failed to broadcast booking created.', [
                            'booking_id' => $fresh->id,
                            'error' => $exception->getMessage(),
                        ]);
                    }

                    // The broadcast is a live nudge only; the persisted row is
                    // what the instructor's bell actually reads.
                    $fresh->instructor?->notify(new BookingRequestedNotification($fresh));

                    // FR 1.9.3 - auto-release the request if the instructor stays silent.
                    ExpireBookingAcceptanceJob::dispatch($fresh)
                        ->delay($fresh->accept_deadline_at ?? now()->addHours(Booking::ACCEPT_WINDOW_HOURS));
                });
            }

            return ['result' => 'ok', 'booking' => $locked->fresh()];
        });

        if ($outcome['result'] === 'expired') {
            return redirect()->route('bookings.create', $outcome['booking']->instructor_id)
                ->with('error', 'Your 15-minute reservation hold expired. Please select your time slot again.');
        }

        if ($outcome['result'] === 'slot_conflict') {
            return redirect()->route('bookings.create', $outcome['booking']->instructor_id)
                ->with('error', $outcome['message'] ?? 'That time slot is no longer available.');
        }

        if ($outcome['result'] === 'awaiting_payment') {
            return redirect()->route('payments.show', $outcome['booking'])
                ->with('info', 'Payment method is already set. Complete the GCash payment from the payment page.');
        }

        if ($outcome['result'] === 'invalid_status') {
            return redirect()->route('student.bookings.show', $outcome['booking'])
                ->with('error', 'The payment method can no longer be changed for this booking.');
        }

        $booking = $outcome['booking'];

        if ($instantBook) {
            return $this->finalizeInstantBook($booking);
        }

        return redirect()->route('bookings.confirmation', $booking->id)
            ->with('success', 'Booking request sent! Your instructor has '
                . Booking::ACCEPT_WINDOW_HOURS . ' hours to accept.');
    }

    /**
     * Explicitly abandon an unfinished checkout booking (from the Waiver,
     * Parental Consent, or Payment Method step) and release the held time slot
     * immediately so another student can book it.
     */
    public function abandon(Request $request, Booking $booking): RedirectResponse
    {
        Gate::authorize('view', $booking);

        $instructorId = $booking->instructor_id;

        DB::transaction(function () use ($booking): void {
            $locked = Booking::with('payment')->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if (($locked->isInStudentCheckout() || $locked->status === BookingStatus::Pending) && ! $locked->hasSubmittedPayment()) {
                $locked->cancel(
                    'Booking abandoned by student before completing waiver and payment selection.',
                    CancellationReason::Student
                );
                $this->availabilityService->releaseForBooking($locked);
            }
        });

        $redirectTo = $request->input('redirect_to');
        $waiverPath = parse_url(route('bookings.waiver', $booking->id, false), PHP_URL_PATH);
        $isSafeRelativeRedirect = is_string($redirectTo)
            && $redirectTo !== ''
            && str_starts_with($redirectTo, '/')
            && !str_starts_with($redirectTo, '//')
            && !preg_match('/[\r\n]/', $redirectTo)
            && parse_url($redirectTo, PHP_URL_PATH) !== $waiverPath;

        $infoMessage = 'Your temporary slot hold has been released. You may update your details or select another time.';

        if ($isSafeRelativeRedirect) {
            return redirect()->to($redirectTo)->with('info', $infoMessage);
        }

        return redirect()->route('bookings.create', $instructorId)
            ->with('info', $infoMessage);
    }

    /**
     * Instant Book: accept on the instructor's behalf, then send the student to
     * whichever step comes next (payment, or straight to confirmation).
     */
    private function finalizeInstantBook(Booking $booking): RedirectResponse
    {
        try {
            $accepted = $this->acceptanceService->accept(
                $booking->fresh(['payment', 'instructor.instructorProfile', 'student', 'surfSpot']),
                auth()->user()
            );
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('student.bookings.show', $booking->id)
                ->with('error', 'Your request was saved but could not be auto-confirmed. '
                    . 'The instructor will respond shortly.');
        }

        if ($accepted->status === BookingStatus::AwaitingPayment) {
            return redirect()->route('student.bookings.show', $accepted->id)
                ->with('success', 'Instant Book confirmed! You have '
                    . Booking::PAYMENT_HOLD_MINUTES . ' minutes to complete payment.');
        }

        return redirect()->route('bookings.confirmation', $accepted->id)
            ->with('success', 'Instant Book confirmed! This lesson is locked in.');
    }

    /**
     * Display booking confirmation (Step 4: Confirmation / Live Request Status)
     */
    public function confirmation(Booking $booking): Response
    {
        Gate::authorize('view', $booking);

        $booking->load(['instructor.instructorProfile', 'surfSpot', 'payment.verifiedBy', 'waiver', 'changeRequests', 'conversation']);
        $booking->setAttribute('is_rescheduled', $booking->isRescheduled());
        $booking->setAttribute('pending_reschedule_proposal', $booking->pendingRescheduleProposal()?->toProposalPayload());
        $booking->setAttribute('has_conversation', $booking->conversation !== null);

        return Inertia::render('Booking/Confirmation', [
            'booking' => $booking,
            'step' => 4,
            'acceptRemainingSeconds' => $booking->getAcceptTimeRemaining(),
            'paymentRemainingSeconds' => $booking->getPaymentTimeRemaining(),
            'slotShare' => $this->buildSlotSharePayload($booking),
            'instructorSessionWarning' => $booking->status === BookingStatus::Pending
                ? $this->availabilityService->activeSessionNotice($booking->instructor ?? $booking->instructor_id)
                : null,
        ]);
    }

    /**
     * Display user's bookings
     */
    public function index(Request $request, RefundCalculatorService $refundCalculator): Response
    {
        $user = auth()->user();
        $this->availabilityService->releaseExpiredCheckoutHoldsForStudent($user->id);

        $filter = $request->query('status', 'all');

        $upcomingStatuses = [
            BookingStatus::Reserved,
            BookingStatus::Pending,
            BookingStatus::AwaitingPayment,
            BookingStatus::Confirmed,
            BookingStatus::InProgress,
        ];

        $closedStatuses = [
            BookingStatus::Cancelled,
            BookingStatus::Declined,
            BookingStatus::PaymentTimeout,
            BookingStatus::Expired,
            BookingStatus::NoShow,
        ];

        $bookings = Booking::with([
            'instructor',
            'surfSpot',
            'payment',
            'latestRefund',
            'changeRequests' => fn($query) => $query->latest('id'),
            'conversation' => function ($query) use ($user) {
                $query->withCount([
                    'messages as unread_count' => fn($messages) => $messages
                        ->where('sender_id', '!=', $user->id)
                        ->whereNull('read_at'),
                ]);
            },
        ])
            ->where('student_id', $user->id)
            ->when($filter === 'upcoming', fn($query) => $query->whereIn('status', $upcomingStatuses))
            ->when($filter === 'completed', fn($query) => $query->where('status', BookingStatus::Completed))
            ->when($filter === 'cancelled', fn($query) => $query->whereIn('status', $closedStatuses))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $bookings->through(function (Booking $booking) use ($refundCalculator) {
            $isReserved = $booking->isInStudentCheckout();
            $proposal = $booking->pendingRescheduleProposal();

            return array_merge($booking->toArray(), [
                'status' => $isReserved ? BookingStatus::Reserved->value : $booking->status->value,
                'is_reserved' => $isReserved,
                'hold_remaining_seconds' => $isReserved ? $booking->getPaymentTimeRemaining() : 0,
                'resume_url' => $isReserved ? route('bookings.waiver', $booking->id) : null,
                'chat_unread_count' => (int) ($booking->conversation?->unread_count ?? 0),
                'has_conversation' => $booking->conversation !== null,
                'can_be_cancelled' => $booking->canBeCancelled(),
                'has_pending_receipt_verification' => $booking->hasPendingReceiptVerification(),
                'has_pending_reschedule_response' => $booking->hasPendingRescheduleResponse(),
                'is_rescheduled' => $booking->isRescheduled(),
                'pending_reschedule_proposal' => $proposal?->toProposalPayload(),
                'refund_preview' => $booking->canBeCancelled()
                    ? $refundCalculator->previewForStudent($booking)
                    : null,
            ]);
        });

        $base = Booking::where('student_id', $user->id);

        return Inertia::render('Student/Bookings/Index', [
            'bookings' => $bookings,
            'filters' => ['status' => $filter],
            'counts' => [
                'all' => (clone $base)->count(),
                'upcoming' => (clone $base)->whereIn('status', $upcomingStatuses)->count(),
                'completed' => (clone $base)->where('status', BookingStatus::Completed)->count(),
                'cancelled' => (clone $base)->whereIn('status', $closedStatuses)->count(),
            ],
        ]);
    }

    /**
     * Display single booking details
     */
    public function show(Booking $booking, RefundCalculatorService $refundCalculator): Response
    {
        Gate::authorize('view', $booking);

        if ($booking->checkoutHoldExpired()) {
            $booking->expireAcceptance('Waiver and checkout hold expired after ' . Booking::CHECKOUT_HOLD_MINUTES . ' minutes.');
            $this->availabilityService->releaseForBooking($booking);
            $booking->refresh();
        }

        $booking->load([
            'instructor.instructorProfile',
            'surfSpot',
            'previousSurfSpot',
            'payment',
            'latestRefund',
            'refunds',
            'review',
            'waiver',
            'waivers',
            'participants.waivers',
            'conversation',
            // Latest first: the student should see SISA's note on the change
            // that cancelled or refused their lesson.
            'changeRequests' => fn($query) => $query->latest('id')->limit(5),
        ]);

        // Payment state decides whether cancellation is still allowed.
        $isReserved = $booking->isInStudentCheckout();
        $proposal = $booking->pendingRescheduleProposal();
        $booking->setAttribute('is_reserved', $isReserved);
        $booking->setAttribute('hold_remaining_seconds', $isReserved ? $booking->getPaymentTimeRemaining() : 0);
        $booking->setAttribute('resume_url', $isReserved ? route('bookings.waiver', $booking->id) : null);
        $booking->setAttribute('can_be_cancelled', $booking->canBeCancelled());
        $booking->setAttribute('has_submitted_payment', $booking->hasSubmittedPayment());
        $booking->setAttribute('has_pending_receipt_verification', $booking->hasPendingReceiptVerification());
        $booking->setAttribute('has_pending_reschedule_response', $booking->hasPendingRescheduleResponse());
        $booking->setAttribute('is_rescheduled', $booking->isRescheduled());
        $booking->setAttribute('pending_reschedule_proposal', $proposal?->toProposalPayload());
        $booking->setAttribute('has_conversation', $booking->conversation !== null);
        $booking->setAttribute(
            'refund_preview',
            $booking->canBeCancelled() ? $refundCalculator->previewForStudent($booking) : null
        );

        $latestChange = $booking->changeRequests->first();

        $booking->setAttribute('latest_change_request', $latestChange ? [
            'id' => $latestChange->id,
            'type' => $latestChange->type?->value,
            'status' => $latestChange->status?->value,
            'reason' => $latestChange->reason,
            'admin_note' => $latestChange->admin_note,
            'reviewed_at' => $latestChange->reviewed_at?->toIso8601String(),
        ] : null);

        return Inertia::render('Student/Bookings/Show', [
            'booking' => $booking,
            'slotShare' => $this->buildSlotSharePayload($booking),
        ]);
    }

    /**
     * Build shared-slot invite metadata for Level 2/3 group sessions so
     * friends can join the exact slot using their own registered accounts.
     *
     * @return array{max_seats: int, remaining_seats: int, share_url: string}|null
     */
    private function buildSlotSharePayload(Booking $booking): ?array
    {
        if (! in_array($booking->status, [
            BookingStatus::Reserved,
            BookingStatus::Pending,
            BookingStatus::AwaitingPayment,
            BookingStatus::Confirmed,
            BookingStatus::InProgress,
        ], true)) {
            return null;
        }

        if (! $booking->date || ! $booking->start_time || ! $booking->end_time) {
            return null;
        }

        $level = $booking->instructor?->instructorProfile?->level;
        if (! $level instanceof InstructorLevel) {
            $level = InstructorLevel::tryFrom((int) $level);
        }

        $skill = $booking->skill_level instanceof SkillLevel
            ? $booking->skill_level
            : SkillLevel::tryFrom((string) $booking->skill_level);

        if (! $level || ! $skill) {
            return null;
        }

        $maxSeats = $level->maxStudentsFor($skill);
        if ($maxSeats <= 1) {
            return null;
        }

        $service = $booking->session_type instanceof SessionType
            ? $booking->session_type
            : SessionType::tryFrom((string) $booking->session_type);

        $date = $booking->date->format('Y-m-d');
        $startTime = $booking->start_time->format('H:i');
        $endTime = $booking->end_time->format('H:i');

        $remaining = $this->availabilityService->remainingCapacity(
            $booking->instructor_id,
            $date,
            $startTime,
            $endTime,
            null,
            $skill,
            $service,
            $booking->surf_spot_id ? (int) $booking->surf_spot_id : null
        );

        return [
            'max_seats' => $maxSeats,
            'remaining_seats' => max(0, $remaining),
            'share_url' => route('bookings.create', [
                'instructor' => $booking->instructor_id,
                'date' => $date,
                'start_time' => $startTime,
            ]),
        ];
    }

    /**
     * Agree to an instructor-initiated reschedule proposal (or confirm a legacy reschedule).
     */
    public function acceptReschedule(Request $request, Booking $booking, BookingChangeService $changeService): RedirectResponse
    {
        Gate::authorize('view', $booking);

        if (auth()->id() !== $booking->student_id) {
            abort(403);
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $resolved = $changeService->acceptRescheduleByStudent(
                $booking,
                auth()->user(),
                $validated['note'] ?? null
            );
        } catch (BookingChangeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if ($resolved && $resolved->isPending()) {
            return back()->with(
                'success',
                'You agreed to the new schedule. Because the lesson starts in under 24 hours, SISA will finalize approval shortly.'
            );
        }

        $dateLabel = $booking->fresh()->date?->format('M d, Y') ?? '';

        return back()->with('success', $dateLabel ? "Booking rescheduled to {$dateLabel}." : 'New lesson schedule accepted.');
    }

    /**
     * Decline an instructor-initiated reschedule proposal while keeping the original slot.
     */
    public function declineReschedule(Request $request, Booking $booking, BookingChangeService $changeService): RedirectResponse
    {
        Gate::authorize('view', $booking);

        if (auth()->id() !== $booking->student_id) {
            abort(403);
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $changeService->declineRescheduleByStudent(
                $booking,
                auth()->user(),
                $validated['note'] ?? null
            );
        } catch (BookingChangeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Reschedule declined. Your booking keeps its original schedule.');
    }

    /**
     * Cancel a booking
     */
    public function cancel(
        Request $request,
        Booking $booking,
        BookingCancellationService $cancellationService
    ): RedirectResponse {
        Gate::authorize('cancel', $booking);

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            $result = $cancellationService->cancel(
                $booking,
                CancellationReason::Student,
                $validated['reason'],
                auth()->user()
            );
        } catch (BookingChangeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $refund = $result['refund'];
        $calc = $result['calculation'];

        if ($refund) {
            $amountLabel = '₱' . number_format((float) $refund->amount, 2);
            $message = $refund->status === RefundStatus::ManualRequired
                ? "Booking cancelled. A {$calc->refundPercentage}% refund ({$amountLabel}) is queued for SISA processing."
                : "Booking cancelled. A {$calc->refundPercentage}% refund ({$amountLabel}) has been initiated.";

            return back()->with('success', $message);
        }

        return back()->with('success', 'Booking cancelled successfully.');
    }
}
