<?php

namespace App\Http\Controllers;

use App\Enums\WaiverType;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\Waiver;
use App\Services\WaiverService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class WaiverController extends Controller
{
    protected WaiverService $waiverService;

    public function __construct(WaiverService $waiverService)
    {
        $this->waiverService = $waiverService;
    }

    /**
     * Show the liability waiver for the next participant who has not signed.
     *
     * With a group, this walks participants one at a time (a guided flow):
     * each person signs their own release, then the flow advances.
     */
    public function showLiability(Booking $booking): Response|RedirectResponse
    {
        $this->authorize('view', $booking);

        if ($booking->checkoutHoldExpired()) {
            $booking->expireAcceptance('Waiver and checkout hold expired after '.Booking::CHECKOUT_HOLD_MINUTES.' minutes.');
            app(\App\Services\AvailabilityService::class)->releaseForBooking($booking);

            return redirect()->route('bookings.create', $booking->instructor_id)
                ->with('error', 'Your 15-minute reservation hold expired. Please select your time slot again.');
        }

        $booking->load(['student.studentProfile', 'instructor.instructorProfile', 'surfSpot', 'participants']);

        $waiverDocument = [
            'title' => config('waiver.title', 'SURF LESSON WAIVER, LIABILITY, AND INDEMNITY AGREEMENT'),
            'clauses' => config('waiver.clauses', []),
            'logo_url' => asset(config('waiver.logo_path', 'images/logo.png')),
        ];

        if ($booking->participants->isNotEmpty()) {
            $participant = $this->waiverService->nextParticipantAwaitingLiability($booking);

            if (! $participant) {
                return $this->continueFlow($booking);
            }

            return Inertia::render('Booking/Waiver', [
                'booking' => $booking,
                'participant' => $participant,
                'progress' => $this->waiverService->waiverProgress($booking),
                'waiverType' => WaiverType::Liability->value,
                'waiverTypeLabel' => WaiverType::Liability->label(),
                'waiverPrefill' => $this->buildWaiverPrefill($booking, $participant),
                'waiverDocument' => $waiverDocument,
                'holdRemainingSeconds' => $booking->getPaymentTimeRemaining(),
                'step' => 2,
            ]);
        }

        // Legacy bookings without participant rows.
        $alreadySigned = $booking->waivers()
            ->where('type', WaiverType::Liability->value)
            ->exists();

        if ($alreadySigned) {
            return $this->continueFlow($booking);
        }

        return Inertia::render('Booking/Waiver', [
            'booking' => $booking,
            'participant' => null,
            'progress' => null,
            'waiverType' => WaiverType::Liability->value,
            'waiverTypeLabel' => WaiverType::Liability->label(),
            'waiverPrefill' => $this->buildWaiverPrefill($booking, null),
            'waiverDocument' => $waiverDocument,
            'holdRemainingSeconds' => $booking->getPaymentTimeRemaining(),
            'step' => 2,
        ]);
    }

    /**
     * Store a liability signature for one participant, then advance.
     */
    public function storeLiability(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorize('view', $booking);

        if ($booking->checkoutHoldExpired()) {
            $booking->expireAcceptance('Waiver and checkout hold expired after '.Booking::CHECKOUT_HOLD_MINUTES.' minutes.');
            app(\App\Services\AvailabilityService::class)->releaseForBooking($booking);

            return redirect()->route('bookings.create', $booking->instructor_id)
                ->with('error', 'Your 15-minute reservation hold expired. Please select your time slot again.');
        }

        $validated = $request->validate([
            'emergency_contact' => 'required|string|max:255',
            'medical_info' => 'nullable|string|max:1000',
            'signed_by' => 'required|string|max:255',
            'signature' => 'required|string', // base64 encoded signature
            'agreement' => 'required|accepted',
            'participant_id' => 'nullable|integer',
            // Fallback fields when a legacy profile or companion lacks them.
            'birthday' => ['nullable', 'date', 'after:1920-01-01', 'before_or_equal:'.now()->subYears(5)->toDateString()],
            'country' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:255',
        ], [
            'emergency_contact.required' => 'Please enter an emergency contact name and number.',
            'signed_by.required' => 'Please manually type your printed full name.',
            'signature.required' => 'Please provide your signature.',
        ]);

        $booking->loadMissing(['student.studentProfile', 'instructor', 'surfSpot']);

        $participant = null;

        if ($booking->participants()->exists()) {
            $participant = isset($validated['participant_id'])
                ? $booking->participants()->find($validated['participant_id'])
                : $this->waiverService->nextParticipantAwaitingLiability($booking);

            if (! $participant) {
                return $this->continueFlow($booking);
            }

            if ($participant->hasSigned(WaiverType::Liability)) {
                return $this->continueFlow($booking);
            }
        } elseif ($booking->waivers()->where('type', WaiverType::Liability->value)->exists()) {
            return $this->continueFlow($booking);
        }

        $isPrimary = $participant === null || $participant->isPrimary();
        $student = $booking->student;
        $studentProfile = $student?->studentProfile;

        // Resolve profile-prefilled demographics authoritatively on the server.
        // If a legacy student account or companion did not yet have birthday /
        // country stored, persist what they supplied on the waiver back to
        // their record so future bookings are prefilled automatically.
        if ($isPrimary) {
            $birthday = $studentProfile?->birthday?->format('Y-m-d') ?: ($validated['birthday'] ?? null);
            $country = $studentProfile?->country ?: (isset($validated['country']) ? trim((string) $validated['country']) : null);
            $email = $student?->email;
            $participantName = $student?->name ?? $participant?->full_name ?? $validated['signed_by'];

            if ($student && $student->isStudent()) {
                $profileUpdates = [];
                if (empty($studentProfile?->birthday) && ! empty($birthday)) {
                    $profileUpdates['birthday'] = $birthday;
                }
                if (empty($studentProfile?->country) && ! empty($country)) {
                    $profileUpdates['country'] = $country;
                }
                if (! empty($profileUpdates)) {
                    if ($studentProfile) {
                        $studentProfile->update($profileUpdates);
                    } else {
                        $studentProfile = $student->studentProfile()->create(array_merge([
                            'skill_level' => 'beginner',
                            'is_first_time' => true,
                            'status' => 'active',
                        ], $profileUpdates));
                    }
                }
            }
        } else {
            $birthday = $participant->birthday?->format('Y-m-d') ?: ($validated['birthday'] ?? null);
            $country = $participant->country ?: (isset($validated['country']) ? trim((string) $validated['country']) : null);
            $email = $participant->email ?: ($validated['email'] ?? null);
            $participantName = $participant->full_name ?? $validated['signed_by'];
        }

        $age = $birthday
            ? Carbon::parse($birthday)->age
            : ($participant?->age ?? $booking->student_age);

        $emergencyContact = trim((string) $validated['emergency_contact']);
        $medicalInfo = isset($validated['medical_info']) && trim((string) $validated['medical_info']) !== ''
            ? trim((string) $validated['medical_info'])
            : null;
        $printedName = trim((string) $validated['signed_by']);

        try {
            $this->waiverService->createLiabilityWaiver(
                $booking,
                $printedName,
                $validated['signature'],
                $participant,
                [
                    'participant_name' => $participantName,
                    'birthday' => $birthday,
                    'age' => $age,
                    'email' => $email,
                    'country' => $country,
                    'hotel_name' => $booking->needs_hotel_pickup ? $booking->hotel_name : null,
                    'emergency_contact' => $emergencyContact,
                    'medical_info' => $medicalInfo,
                    'surf_shop' => config('waiver.surf_shop', config('app.name', 'SiaSurf')),
                    'instructor_name' => $booking->instructor?->name ?? '',
                    'witness_name' => $booking->instructor?->name ?? '',
                    'signed_by_guardian' => $age !== null && $age < 18,
                ]
            );

            // Sync the manually entered emergency contact, medical info, and any
            // fallback demographics onto the participant so instructors see them
            // on the session sheet and parental consent can reuse them if needed.
            if ($participant) {
                [$ecName, $ecPhone] = $this->splitEmergencyContact($emergencyContact);

                $participantUpdates = [
                    'emergency_contact_name' => $ecName,
                    'emergency_contact_phone' => $ecPhone,
                ];

                if ($request->exists('medical_info')) {
                    $participantUpdates['medical_info'] = $medicalInfo;
                }

                if (empty($participant->birthday) && ! empty($birthday)) {
                    $participantUpdates['birthday'] = $birthday;
                }
                if (empty($participant->country) && ! empty($country)) {
                    $participantUpdates['country'] = $country;
                }
                if (empty($participant->email) && ! empty($email)) {
                    $participantUpdates['email'] = $email;
                }

                $participant->update($participantUpdates);
            }

            return $this->continueFlow($booking)
                ->with('success', 'Liability waiver signed successfully.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to save waiver: ' . $e->getMessage());
        }
    }

    /**
     * Download an A4 portrait PDF copy of the waiver currently shown in the waiver window.
     */
    public function downloadPreviewPdf(Request $request, Booking $booking)
    {
        $this->authorize('view', $booking);

        $validated = $request->validate([
            'participant_id' => 'nullable|integer',
            'emergency_contact' => 'nullable|string|max:255',
            'medical_info' => 'nullable|string|max:1000',
            'signed_by' => 'nullable|string|max:255',
            'signature' => 'nullable|string',
            'birthday' => ['nullable', 'date', 'after:1920-01-01', 'before_or_equal:'.now()->subYears(5)->toDateString()],
            'country' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:255',
        ]);

        $booking->load(['student.studentProfile', 'instructor.instructorProfile', 'surfSpot', 'participants']);

        $participant = null;
        if ($booking->participants->isNotEmpty()) {
            $participant = isset($validated['participant_id'])
                ? $booking->participants->firstWhere('id', (int) $validated['participant_id'])
                : $this->waiverService->nextParticipantAwaitingLiability($booking);
        }

        $prefill = $this->buildWaiverPrefill($booking, $participant);

        $birthdayFormatted = $prefill['birthday_formatted'];
        $age = $prefill['age'];
        if (empty($prefill['birthday']) && ! empty($validated['birthday'])) {
            $parsedBirthday = Carbon::parse($validated['birthday']);
            $birthdayFormatted = $parsedBirthday->format('F d, Y');
            $age = $parsedBirthday->age;
        }

        $country = ! empty($prefill['country'])
            ? $prefill['country']
            : (isset($validated['country']) ? trim((string) $validated['country']) : '');

        $email = ! empty($prefill['email'])
            ? $prefill['email']
            : (isset($validated['email']) ? trim((string) $validated['email']) : '');

        $medicalInfo = $request->exists('medical_info')
            ? (trim((string) ($validated['medical_info'] ?? '')) ?: null)
            : $prefill['medical_info'];

        $snapshot = [
            'participant_name' => $prefill['name'],
            'birthday_formatted' => $birthdayFormatted ?? '',
            'age' => $age,
            'email' => $email,
            'country' => $country,
            'needs_hotel_pickup' => $prefill['needs_hotel_pickup'],
            'hotel_name' => $prefill['hotel_name'],
            'emergency_contact' => trim((string) ($validated['emergency_contact'] ?? '')),
            'medical_info' => $medicalInfo,
            'surf_shop' => $prefill['surf_shop'],
            'instructor_name' => $prefill['instructor_name'],
            'witness_name' => $prefill['witness_name'],
            'signed_by_guardian' => $age !== null && $age < 18,
            'signature_data' => $validated['signature'] ?? null,
            'lesson_date' => $booking->date ? Carbon::parse($booking->date)->format('F d, Y') : '',
        ];

        $signedBy = trim((string) ($validated['signed_by'] ?? ''));
        $pdfBytes = $this->waiverService->renderLiabilityWaiverPdf($booking, $snapshot, $signedBy, now(), true);

        $filename = 'SiaSurf_Waiver_SURF-' . str_pad((string) $booking->id, 5, '0', STR_PAD_LEFT) . '.pdf';

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Build the prefilled waiver payload matching the official SISA form.
     *
     * @return array<string, mixed>
     */
    private function buildWaiverPrefill(Booking $booking, ?BookingParticipant $participant): array
    {
        $isPrimary = $participant === null || $participant->isPrimary();
        $student = $booking->student;
        $studentProfile = $student?->studentProfile;

        $birthdayCarbon = $isPrimary
            ? ($studentProfile?->birthday ?? $participant?->birthday)
            : $participant?->birthday;

        $age = $birthdayCarbon?->age
            ?? $participant?->age
            ?? $booking->student_age;

        $country = $isPrimary
            ? ($studentProfile?->country ?? $participant?->country)
            : $participant?->country;

        $email = $isPrimary
            ? ($student?->email ?? $participant?->email)
            : $participant?->email;

        $name = $isPrimary
            ? ($student?->name ?? $participant?->full_name ?? '')
            : ($participant?->full_name ?? '');

        $needsHotelPickup = (bool) $booking->needs_hotel_pickup;

        return [
            'is_primary' => $isPrimary,
            'is_minor' => $age !== null && $age < 18,
            'name' => $name,
            'birthday' => $birthdayCarbon?->format('Y-m-d'),
            'birthday_formatted' => $birthdayCarbon?->format('F d, Y'),
            'age' => $age,
            'email' => $email,
            'country' => $country,
            'needs_hotel_pickup' => $needsHotelPickup,
            'hotel_name' => $needsHotelPickup ? $booking->hotel_name : null,
            'medical_info' => $participant?->medical_info,
            'surf_shop' => config('waiver.surf_shop', config('app.name', 'SiaSurf')),
            'instructor_name' => $booking->instructor?->name ?? '',
            'witness_name' => $booking->instructor?->name ?? '',
            'date' => now()->format('F d, Y'),
        ];
    }

    /**
     * Split a combined "Name & Number" emergency contact string into name and
     * phone pieces for display on instructor booking sheets.
     *
     * @return array{0: string, 1: string}
     */
    private function splitEmergencyContact(string $raw): array
    {
        $trimmed = trim($raw);

        if (preg_match('/^(.*?)[\s,\-\/\|]+(\+?[0-9][0-9\s\-().]{5,})$/', $trimmed, $matches)) {
            $name = trim($matches[1], " \t\n\r\0\x0B,-/|");
            $phone = trim($matches[2]);

            return [$name !== '' ? $name : 'Emergency Contact', $phone];
        }

        return [$trimmed, $trimmed];
    }

    /**
     * Show parental consent for the next minor who needs it.
     */
    public function showParentalConsent(Booking $booking): Response|RedirectResponse
    {
        $this->authorize('view', $booking);

        if ($booking->checkoutHoldExpired()) {
            $booking->expireAcceptance('Waiver and checkout hold expired after '.Booking::CHECKOUT_HOLD_MINUTES.' minutes.');
            app(\App\Services\AvailabilityService::class)->releaseForBooking($booking);

            return redirect()->route('bookings.create', $booking->instructor_id)
                ->with('error', 'Your 15-minute reservation hold expired. Please select your time slot again.');
        }

        $booking->load(['instructor', 'surfSpot', 'participants']);

        if ($booking->participants->isNotEmpty()) {
            $participant = $this->waiverService->nextParticipantAwaitingParentalConsent($booking);

            if (! $participant) {
                return $this->continueFlow($booking);
            }

            if (! $participant->hasSigned(WaiverType::Liability)) {
                return redirect()->route('bookings.waiver', $booking)
                    ->with('error', 'Please sign the liability waiver first.');
            }

            return Inertia::render('Booking/ParentalConsent', [
                'booking' => $booking,
                'participant' => $participant,
                'progress' => $this->waiverService->waiverProgress($booking),
                'holdRemainingSeconds' => $booking->getPaymentTimeRemaining(),
                'step' => 2,
            ]);
        }

        // Legacy booking without participant rows.
        if ($booking->student_age >= 18) {
            return $this->continueFlow($booking);
        }

        if (! $booking->waivers()->where('type', WaiverType::Liability->value)->exists()) {
            return redirect()->route('bookings.waiver', $booking)
                ->with('error', 'Please sign the liability waiver first.');
        }

        if ($booking->waivers()->where('type', WaiverType::ParentalConsent->value)->exists()) {
            return $this->continueFlow($booking);
        }

        return Inertia::render('Booking/ParentalConsent', [
            'booking' => $booking,
            'participant' => null,
            'progress' => null,
            'holdRemainingSeconds' => $booking->getPaymentTimeRemaining(),
            'step' => 2,
        ]);
    }

    /**
     * Store parental consent for one minor, then advance.
     */
    public function storeParentalConsent(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorize('view', $booking);

        if ($booking->checkoutHoldExpired()) {
            $booking->expireAcceptance('Waiver and checkout hold expired after '.Booking::CHECKOUT_HOLD_MINUTES.' minutes.');
            app(\App\Services\AvailabilityService::class)->releaseForBooking($booking);

            return redirect()->route('bookings.create', $booking->instructor_id)
                ->with('error', 'Your 15-minute reservation hold expired. Please select your time slot again.');
        }

        $validated = $request->validate([
            'parent_name' => 'required|string|max:255',
            'parent_signature' => 'required|string',
            'emergency_contact_name' => 'required|string|max:255',
            'emergency_contact_phone' => 'required|string|max:50',
            'medical_info' => 'nullable|string|max:1000',
            'agreement' => 'required|accepted',
            'participant_id' => 'nullable|integer',
        ]);

        $participant = null;

        if ($booking->participants()->exists()) {
            $participant = isset($validated['participant_id'])
                ? $booking->participants()->find($validated['participant_id'])
                : $this->waiverService->nextParticipantAwaitingParentalConsent($booking);

            if (! $participant) {
                return $this->continueFlow($booking);
            }

            if (! $participant->hasSigned(WaiverType::Liability)) {
                return redirect()->route('bookings.waiver', $booking)
                    ->with('error', 'Please sign the liability waiver first.');
            }

            if ($participant->hasSigned(WaiverType::ParentalConsent)) {
                return $this->continueFlow($booking);
            }
        } else {
            if ($booking->student_age >= 18) {
                return $this->continueFlow($booking);
            }

            if ($booking->waivers()->where('type', WaiverType::ParentalConsent->value)->exists()) {
                return $this->continueFlow($booking);
            }
        }

        try {
            $this->waiverService->createParentalConsent(
                $booking,
                $validated['parent_name'],
                $validated['parent_signature'],
                $validated['emergency_contact_name'],
                $validated['emergency_contact_phone'],
                $validated['medical_info'] ?? null,
                $participant
            );

            // The guardian details also apply to the participant's record.
            if ($participant) {
                $participant->update([
                    'emergency_contact_name' => $validated['emergency_contact_name'],
                    'emergency_contact_phone' => $validated['emergency_contact_phone'],
                    'medical_info' => $validated['medical_info'] ?? $participant->medical_info,
                    'guardian_name' => $validated['parent_name'],
                    'guardian_phone' => $participant->guardian_phone,
                ]);
            }

            return $this->continueFlow($booking)
                ->with('success', 'Parental consent provided.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to save consent: ' . $e->getMessage());
        }
    }

    /**
     * Route the student to whichever waiver step is still outstanding, then
     * to payment once everyone is covered.
     */
    private function continueFlow(Booking $booking): RedirectResponse
    {
        if ($this->waiverService->nextParticipantAwaitingLiability($booking)) {
            return redirect()->route('bookings.waiver', $booking);
        }

        if ($this->waiverService->nextParticipantAwaitingParentalConsent($booking)) {
            return redirect()->route('bookings.parental-consent', $booking);
        }

        $missing = $this->waiverService->getMissingWaiverTypes($booking);

        if (in_array(WaiverType::Liability->value, $missing, true)) {
            return redirect()->route('bookings.waiver', $booking);
        }

        if (in_array(WaiverType::ParentalConsent->value, $missing, true)) {
            return redirect()->route('bookings.parental-consent', $booking);
        }

        return redirect()->route('bookings.payment', $booking)
            ->with('success', 'All waivers signed. Please proceed to payment.');
    }

    /**
     * Download waiver PDF
     */
    public function download(Waiver $waiver)
    {
        $this->authorize('view', $waiver->booking);

        return $this->waiverService->downloadWaiver($waiver);
    }

    /**
     * View waiver PDF inline
     */
    public function view(Waiver $waiver)
    {
        $this->authorize('view', $waiver->booking);

        $path = Storage::disk('local')->path($waiver->pdf_path);

        return response()->file($path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="SiaSurf_Waiver_' . $waiver->booking_id . '.pdf"',
        ]);
    }

    /**
     * Get waiver status for a booking
     */
    public function status(Booking $booking)
    {
        $this->authorize('view', $booking);

        $status = [
            'liability_signed' => false,
            'parental_consent_signed' => false,
            'parental_consent_required' => $booking->student_age < 18,
            'all_signed' => $this->waiverService->hasRequiredWaivers($booking),
            'progress' => $this->waiverService->waiverProgress($booking),
        ];

        $liability = $booking->waivers()
            ->where('type', WaiverType::Liability->value)
            ->first();

        if ($liability) {
            $status['liability_signed'] = true;
            $status['liability_waiver'] = [
                'id' => $liability->id,
                'signed_at' => $liability->signed_at,
                'download_url' => route('waivers.download', $liability),
            ];
        }

        if ($booking->student_age < 18) {
            $consent = $booking->waivers()
                ->where('type', WaiverType::ParentalConsent->value)
                ->first();

            if ($consent) {
                $status['parental_consent_signed'] = true;
                $status['parental_consent_waiver'] = [
                    'id' => $consent->id,
                    'signed_at' => $consent->signed_at,
                    'download_url' => route('waivers.download', $consent),
                ];
            }
        }

        return response()->json($status);
    }
}
