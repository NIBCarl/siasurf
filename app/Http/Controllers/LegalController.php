<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LegalController extends Controller
{
    public function privacy(Request $request): Response
    {
        return Inertia::render('Public/Legal/PrivacyPolicy', [
            'legal' => $this->legalMeta(),
        ]);
    }

    public function terms(Request $request): Response
    {
        return Inertia::render('Public/Legal/TermsOfService', [
            'legal' => $this->legalMeta(),
            'rules' => [
                'teaching_min' => (int) config('siasurf.services.teaching.min_rate', 600),
                'teaching_max' => (int) config('siasurf.services.teaching.max_rate', 1000),
                'guiding_min' => (int) config('siasurf.services.guiding.min_rate', 800),
                'guiding_max' => (int) config('siasurf.services.guiding.max_rate', 1500),
                'coaching_min' => (int) config('siasurf.services.coaching.min_rate', 1500),
                'coaching_max' => (int) config('siasurf.services.coaching.max_rate', 2000),
                'service_fee_percent' => (float) config('siasurf.pricing.service_fee_percent', 7.0),
                'cash_remittance_days' => (int) config('siasurf.pricing.cash_remittance_days', 7),
                'horizon_days' => (int) config('siasurf.booking.horizon_days', 60),
                'standard_hours' => (int) config('siasurf.session.standard_hours', 2),
                'refund_long_term_threshold_days' => (int) config('siasurf.refunds.long_term_threshold_days', 30),
                'refund_long_term_window_days' => (int) config('siasurf.refunds.long_term_window_days', 30),
                'refund_short_term_full_days' => (int) config('siasurf.refunds.short_term_full_days', 7),
                'refund_short_term_half_days' => (int) config('siasurf.refunds.short_term_half_days', 3),
                'refund_short_term_half_percent' => (int) config('siasurf.refunds.short_term_half_percent', 50),
                'strike_fine_amount' => (int) config('siasurf.strikes.fine_amount', 500),
                'strike_tier2_days' => (int) config('siasurf.strikes.tier2_suspension_days', 7),
                'strike_tier3_days' => (int) config('siasurf.strikes.tier3_suspension_days', 30),
            ],
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function legalMeta(): array
    {
        return [
            'effective_date' => (string) config('siasurf.legal.effective_date', 'November 25, 2026'),
            'last_updated' => (string) config('siasurf.legal.last_updated', 'November 25, 2026'),
            'organization' => (string) config('siasurf.legal.organization', 'Siargao Island Surfing Association (SISA)'),
            'dpo_name' => (string) config('siasurf.legal.dpo_name', 'SISA Data Protection Officer'),
            'contact_email' => (string) (config('siasurf.legal.contact_email') ?: config('mail.from.address', 'support@siasurf.ph')),
            'contact_phone' => (string) config('siasurf.legal.contact_phone', 'Available at the SISA General Luna Office'),
            'office_address' => (string) config('siasurf.legal.office_address', 'General Luna, Siargao Island, Surigao del Norte, Philippines'),
        ];
    }
}
