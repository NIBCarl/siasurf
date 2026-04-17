<?php

namespace App\Http\Controllers;

use App\Enums\WaiverType;
use App\Models\Booking;
use App\Models\Waiver;
use App\Services\WaiverService;
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
     * Show liability waiver signing page
     */
    public function showLiability(Booking $booking): Response
    {
        $this->authorize('view', $booking);

        // Check if already signed
        $existingWaiver = $booking->waiver()
            ->where('type', WaiverType::Liability->value)
            ->first();

        if ($existingWaiver) {
            return redirect()->route('bookings.payment', $booking)
                ->with('info', 'Liability waiver already signed.');
        }

        return Inertia::render('Booking/Waiver', [
            'booking' => $booking->load(['instructor', 'surfSpot']),
            'waiverType' => WaiverType::Liability->value,
            'waiverTypeLabel' => WaiverType::Liability->label(),
        ]);
    }

    /**
     * Store liability waiver signature
     */
    public function storeLiability(Request $request, Booking $booking)
    {
        $this->authorize('view', $booking);

        // Check if already signed
        $existingWaiver = $booking->waiver()
            ->where('type', WaiverType::Liability->value)
            ->first();

        if ($existingWaiver) {
            return redirect()->route('bookings.payment', $booking);
        }

        $validated = $request->validate([
            'signed_by' => 'required|string|max:255',
            'signature' => 'required|string', // base64 encoded signature
            'agreement' => 'required|accepted',
        ]);

        try {
            $waiver = $this->waiverService->createLiabilityWaiver(
                $booking,
                $validated['signed_by'],
                $validated['signature']
            );

            // Check if parental consent is needed
            if ($booking->student_age < 18) {
                return redirect()->route('bookings.parental-consent', $booking)
                    ->with('success', 'Liability waiver signed. Parental consent required next.');
            }

            return redirect()->route('bookings.payment', $booking)
                ->with('success', 'Waiver signed successfully. Please proceed to payment.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to save waiver: ' . $e->getMessage());
        }
    }

    /**
     * Show parental consent form
     */
    public function showParentalConsent(Booking $booking): Response
    {
        $this->authorize('view', $booking);

        // Only for minors
        if ($booking->student_age >= 18) {
            return redirect()->route('bookings.payment', $booking);
        }

        // Check if liability is signed first
        $liabilityWaiver = $booking->waiver()
            ->where('type', WaiverType::Liability->value)
            ->first();

        if (!$liabilityWaiver) {
            return redirect()->route('bookings.waiver', $booking)
                ->with('error', 'Please sign the liability waiver first.');
        }

        // Check if parental consent already signed
        $existingConsent = $booking->waiver()
            ->where('type', WaiverType::ParentalConsent->value)
            ->first();

        if ($existingConsent) {
            return redirect()->route('bookings.payment', $booking)
                ->with('info', 'Parental consent already provided.');
        }

        return Inertia::render('Booking/ParentalConsent', [
            'booking' => $booking->load(['instructor', 'surfSpot']),
        ]);
    }

    /**
     * Store parental consent
     */
    public function storeParentalConsent(Request $request, Booking $booking)
    {
        $this->authorize('view', $booking);

        // Only for minors
        if ($booking->student_age >= 18) {
            return redirect()->route('bookings.payment', $booking);
        }

        // Check if already signed
        $existingConsent = $booking->waiver()
            ->where('type', WaiverType::ParentalConsent->value)
            ->first();

        if ($existingConsent) {
            return redirect()->route('bookings.payment', $booking);
        }

        $validated = $request->validate([
            'parent_name' => 'required|string|max:255',
            'parent_signature' => 'required|string',
            'emergency_contact_name' => 'required|string|max:255',
            'emergency_contact_phone' => 'required|string|max:50',
            'medical_info' => 'nullable|string|max:1000',
            'agreement' => 'required|accepted',
        ]);

        try {
            $waiver = $this->waiverService->createParentalConsent(
                $booking,
                $validated['parent_name'],
                $validated['parent_signature'],
                $validated['emergency_contact_name'],
                $validated['emergency_contact_phone'],
                $validated['medical_info'] ?? null
            );

            return redirect()->route('bookings.payment', $booking)
                ->with('success', 'Parental consent provided. Please proceed to payment.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to save consent: ' . $e->getMessage());
        }
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
        ];

        $liability = $booking->waiver()
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
            $consent = $booking->waiver()
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
