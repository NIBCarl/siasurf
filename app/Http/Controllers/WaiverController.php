<?php

namespace App\Http\Controllers;

use App\Enums\WaiverType;
use App\Models\Booking;
use App\Models\Waiver;
use App\Services\WaiverService;
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

        $booking->load(['instructor', 'surfSpot', 'participants']);

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
        ]);
    }

    /**
     * Store a liability signature for one participant, then advance.
     */
    public function storeLiability(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorize('view', $booking);

        $validated = $request->validate([
            'signed_by' => 'required|string|max:255',
            'signature' => 'required|string', // base64 encoded signature
            'agreement' => 'required|accepted',
            'participant_id' => 'nullable|integer',
        ]);

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

        try {
            $this->waiverService->createLiabilityWaiver(
                $booking,
                $validated['signed_by'],
                $validated['signature'],
                $participant
            );

            return $this->continueFlow($booking)
                ->with('success', 'Liability waiver signed successfully.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to save waiver: ' . $e->getMessage());
        }
    }

    /**
     * Show parental consent for the next minor who needs it.
     */
    public function showParentalConsent(Booking $booking): Response|RedirectResponse
    {
        $this->authorize('view', $booking);

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
        ]);
    }

    /**
     * Store parental consent for one minor, then advance.
     */
    public function storeParentalConsent(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorize('view', $booking);

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
            'Content-Disposition' => 'inline; filename="SiaSurf_Waiver_' . $waiver->booking_id . '.pdf"'
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
