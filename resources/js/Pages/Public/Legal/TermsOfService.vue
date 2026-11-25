<script setup lang="ts">
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    EnvelopeIcon,
    MapPinIcon,
    PhoneIcon,
    ScaleIcon,
    ShieldCheckIcon,
} from '@heroicons/vue/24/outline';

interface LegalMeta {
    effective_date: string;
    last_updated: string;
    organization: string;
    dpo_name: string;
    contact_email: string;
    contact_phone: string;
    office_address: string;
}

interface PlatformRules {
    teaching_min: number;
    teaching_max: number;
    guiding_min: number;
    guiding_max: number;
    coaching_min: number;
    coaching_max: number;
    service_fee_percent: number;
    cash_remittance_days: number;
    horizon_days: number;
    standard_hours: number;
    refund_long_term_threshold_days: number;
    refund_long_term_window_days: number;
    refund_short_term_full_days: number;
    refund_short_term_half_days: number;
    refund_short_term_half_percent: number;
    strike_fine_amount: number;
    strike_tier2_days: number;
    strike_tier3_days: number;
}

defineProps<{
    legal: LegalMeta;
    rules: PlatformRules;
}>();

const sections = [
    { id: 'acceptance', label: '1. Acceptance & Role' },
    { id: 'accounts-verification', label: '2. Accounts & Verification' },
    { id: 'safety-rules', label: '3. Surf Safety Rules' },
    { id: 'booking-holds', label: '4. Bookings & Holds' },
    { id: 'pricing-payments', label: '5. Pricing & Payments' },
    { id: 'cancellation-refunds', label: '6. Cancellations & Refunds' },
    { id: 'digital-waivers', label: '7. Digital Waivers & Minors' },
    { id: 'reviews-strikes', label: '8. Reviews & 3-Strike Rules' },
    { id: 'acceptable-use', label: '9. Acceptable Use & Chat' },
    { id: 'ocean-risk', label: '10. Ocean Risk & Forecasts' },
    { id: 'privacy-link', label: '11. Privacy & Data' },
    { id: 'governing-law', label: '12. Governing Law & Contact' },
];

const formatPeso = (amount: number): string => `₱${amount.toLocaleString('en-PH')}`;
</script>

<template>
    <Head title="Terms of Service" />

    <AppSidebarLayout>
        <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8 lg:py-12">
            <!-- Hero Card -->
            <div class="mb-8 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="inline-flex items-center gap-2 rounded-full border border-lagoon-200 bg-lagoon-50 px-3 py-1 text-xs font-semibold text-lagoon-800">
                            <ScaleIcon class="h-4 w-4 shrink-0" aria-hidden="true" />
                            <span>Platform Rules &amp; SISA Governance</span>
                        </div>
                        <h1 class="mt-3 text-2xl font-extrabold tracking-tight text-deep-950 sm:text-3xl">
                            SiaSurf Terms of Service
                        </h1>
                        <p class="mt-2 text-body-sm text-stone-600">
                            The binding rules governing surf lesson bookings, mandatory safety eligibility, pricing, cancellations, digital waivers, and SISA instructor governance on SiaSurf.
                        </p>
                    </div>

                    <div class="shrink-0">
                        <Link
                            :href="route('legal.privacy')"
                            class="inline-flex min-h-[40px] items-center gap-2 rounded-control border border-sand-200 bg-sand-50 px-4 py-2 text-sm font-semibold text-deep-950 transition hover:bg-sand-100"
                        >
                            <ShieldCheckIcon class="h-4 w-4 shrink-0 text-lagoon-700" aria-hidden="true" />
                            <span>Privacy Policy</span>
                        </Link>
                    </div>
                </div>

                <div class="mt-5 flex flex-wrap gap-x-6 gap-y-2 border-t border-sand-200 pt-4 text-xs text-stone-600">
                    <div>
                        <span class="font-semibold text-deep-950">Effective Date:</span>
                        {{ legal.effective_date }}
                    </div>
                    <div>
                        <span class="font-semibold text-deep-950">Last Updated:</span>
                        {{ legal.last_updated }}
                    </div>
                    <div>
                        <span class="font-semibold text-deep-950">Governing Authority:</span>
                        {{ legal.organization }}
                    </div>
                </div>
            </div>

            <div class="grid gap-8 lg:grid-cols-[240px_1fr] lg:items-start">
                <!-- Mobile Quick Jump -->
                <div class="lg:hidden">
                    <details class="rounded-card border border-sand-200 bg-white p-4 shadow-card">
                        <summary class="cursor-pointer text-sm font-bold text-deep-950">
                            Jump to Section (12 Sections)
                        </summary>
                        <nav aria-label="Terms of service sections" class="mt-3 grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                            <a
                                v-for="sec in sections"
                                :key="`mob-${sec.id}`"
                                :href="`#${sec.id}`"
                                class="rounded-md px-2.5 py-1.5 text-xs font-medium text-stone-700 transition hover:bg-sand-100 hover:text-deep-950"
                            >
                                {{ sec.label }}
                            </a>
                        </nav>
                    </details>
                </div>

                <!-- Desktop Sticky TOC -->
                <aside class="hidden lg:sticky lg:top-24 lg:block">
                    <div class="rounded-card border border-sand-200 bg-white p-4 shadow-card">
                        <p class="mb-3 text-xs font-bold uppercase tracking-[0.08em] text-stone-500">
                            On this page
                        </p>
                        <nav aria-label="Terms of service contents" class="space-y-1">
                            <a
                                v-for="sec in sections"
                                :key="sec.id"
                                :href="`#${sec.id}`"
                                class="block rounded-md px-2.5 py-1.5 text-xs font-medium text-stone-600 transition hover:bg-sand-100 hover:text-deep-950"
                            >
                                {{ sec.label }}
                            </a>
                        </nav>
                    </div>
                </aside>

                <!-- Document Content -->
                <article class="min-w-0 space-y-6">
                    <!-- Section 1 -->
                    <section id="acceptance" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">1. Acceptance of Terms &amp; Platform Role</h2>
                        <p class="mt-2 text-body-sm leading-relaxed text-stone-700">
                            By creating an account, browsing verified instructors, or booking a surf session on SiaSurf, you agree to be bound by these Terms of Service and our <Link :href="route('legal.privacy')" class="font-medium text-lagoon-700 underline hover:text-deep-950">Privacy Policy</Link>.
                        </p>
                        <p class="mt-3 text-body-sm leading-relaxed text-stone-700">
                            SiaSurf serves as the digital booking, verification, and safety monitoring platform operating in coordination with the <strong class="text-deep-950">{{ legal.organization }}</strong> and local authorities in Siargao Island, Philippines. Surf lessons themselves are delivered in person by independent SISA-verified surf instructors.
                        </p>
                    </section>

                    <!-- Section 2 -->
                    <section id="accounts-verification" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">2. Accounts, Roles &amp; Instructor Verification</h2>
                        <ul class="mt-3 list-disc space-y-2 pl-5 text-body-sm leading-relaxed text-stone-700">
                            <li><strong class="text-deep-950">Accurate Registration:</strong> All users must verify their email address and provide accurate identity, age, and contact details. One user account may not impersonate another individual or register duplicate instructor credentials.</li>
                            <li><strong class="text-deep-950">Student / Tourist Onboarding:</strong> Students must complete the water-safety and skill pre-assessment before requesting a booking. Skill levels (Beginner, Intermediate, Advanced) are system-assigned from assessment answers and may only be upgraded through SISA-approved processes.</li>
                            <li><strong class="text-deep-950">Instructor Verification:</strong> Surf instructors remain hidden from public discovery and booking until they complete onboarding, upload valid supporting certificates (such as WaSAR, BLS, ISA/SISA, or surf skills credentials), and receive active SISA approval. Verified instructors receive a permanent QR verification link reflecting live status.</li>
                        </ul>
                    </section>

                    <!-- Section 3 -->
                    <section id="safety-rules" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">3. Mandatory Surf Safety Rules (Zero Override)</h2>
                        <p class="mt-2 text-body-sm leading-relaxed text-stone-700">
                            Every booking request is automatically validated by SiaSurf&rsquo;s safety engine prior to creation. There is <strong class="text-deep-950">no administrative override</strong> for these rules:
                        </p>
                        <ul class="mt-3 list-disc space-y-2 pl-5 text-body-sm leading-relaxed text-stone-700">
                            <li><strong class="text-deep-950">Level 1 Instructors:</strong> Maximum of <strong class="text-deep-950">1 student</strong> per session, <strong class="text-deep-950">Beginner</strong> surfers only.</li>
                            <li><strong class="text-deep-950">Level 2 Instructors:</strong> Up to <strong class="text-deep-950">1 Beginner</strong> surfer, OR up to <strong class="text-deep-950">2 Intermediate</strong> surfers per session.</li>
                            <li><strong class="text-deep-950">Level 3 Instructors:</strong> <strong class="text-deep-950">1 to 3 students</strong> of the <strong class="text-deep-950">same skill level</strong> (Beginner, Intermediate, or Advanced).</li>
                            <li><strong class="text-deep-950">Age Requirements:</strong> Surfers under <strong class="text-deep-950">5 years old</strong> cannot be booked. Surfers aged <strong class="text-deep-950">5–12 years</strong> require a <strong class="text-deep-950">Level 3</strong> instructor. All minors under <strong class="text-deep-950">18 years old</strong> require parent or legal guardian consent.</li>
                            <li><strong class="text-deep-950">Surf Spot &amp; Skill Matching:</strong> The chosen surf break&rsquo;s difficulty must never exceed the skill level of the least-experienced participant in the booking.</li>
                            <li><strong class="text-deep-950">Service &amp; Certification Alignment:</strong> Instructor certification level, authorized lesson types (Teaching, Guiding, Coaching), and assigned surf zones must match the requested lesson.</li>
                        </ul>
                    </section>

                    <!-- Section 4 -->
                    <section id="booking-holds" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">4. Booking Process, Holds &amp; Acceptance Windows</h2>
                        <ul class="mt-3 list-disc space-y-2 pl-5 text-body-sm leading-relaxed text-stone-700">
                            <li><strong class="text-deep-950">Booking Horizon &amp; Duration:</strong> Lessons may be booked up to <strong class="text-deep-950">{{ rules.horizon_days }} days</strong> ahead. The standard lesson length is <strong class="text-deep-950">{{ rules.standard_hours }} hours</strong> (with 1-hour intro options and flexible whole-hour end times where offered).</li>
                            <li><strong class="text-deep-950">15-Minute Checkout Hold:</strong> Selecting a slot places a temporary 15-minute reservation hold while you complete the digital waiver, parental consent (if required), and payment selection. Unfinished holds expire automatically and release the slot.</li>
                            <li><strong class="text-deep-950">Instructor Confirmation Window:</strong> Instructors have up to <strong class="text-deep-950">2 hours</strong> to accept or decline a pending request (clamped to <strong class="text-deep-950">1 hour</strong> before lesson start). Instructors with Instant Book enabled confirm eligible slots automatically.</li>
                        </ul>
                    </section>

                    <!-- Section 5 -->
                    <section id="pricing-payments" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">5. Standardized Pricing, Service Fees &amp; Payments</h2>
                        <ul class="mt-3 list-disc space-y-2 pl-5 text-body-sm leading-relaxed text-stone-700">
                            <li>
                                <strong class="text-deep-950">Authorized Hourly Rates (per student):</strong>
                                Teaching <strong class="text-deep-950">{{ formatPeso(rules.teaching_min) }}–{{ formatPeso(rules.teaching_max) }}/hr</strong> &middot;
                                Guiding <strong class="text-deep-950">{{ formatPeso(rules.guiding_min) }}–{{ formatPeso(rules.guiding_max) }}/hr</strong> &middot;
                                Coaching <strong class="text-deep-950">{{ formatPeso(rules.coaching_min) }}–{{ formatPeso(rules.coaching_max) }}/hr</strong>.
                            </li>
                            <li><strong class="text-deep-950">Platform Service Fee:</strong> A <strong class="text-deep-950">{{ rules.service_fee_percent }}% platform service fee</strong> is added on top of the lesson price at checkout and is <strong class="text-deep-950">non-refundable</strong>.</li>
                            <li><strong class="text-deep-950">Payment Methods:</strong> Tourists may pay online via <strong class="text-deep-950">GCash</strong> (processed through Xendit or PayMongo) after instructor confirmation, or select <strong class="text-deep-950">Cash on Hand</strong> settled directly at the beach. Instructors must record Cash on Hand payment status within 24 hours.</li>
                            <li><strong class="text-deep-950">Instructor Ledger &amp; Cash Fee Remittance:</strong> For completed Cash on Hand lessons, the {{ rules.service_fee_percent }}% platform service fee is tracked on the instructor&rsquo;s daily earnings ledger and must be settled or offset with SISA within <strong class="text-deep-950">{{ rules.cash_remittance_days }} days</strong>.</li>
                        </ul>
                    </section>

                    <!-- Section 6 -->
                    <section id="cancellation-refunds" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">6. Cancellation &amp; Refund Policy</h2>
                        <p class="mt-2 text-body-sm leading-relaxed text-stone-700">
                            Refund eligibility applies to the <strong class="text-deep-950">lesson price</strong> only; the {{ rules.service_fee_percent }}% platform service fee is non-refundable once processed:
                        </p>
                        <ul class="mt-3 list-disc space-y-2 pl-5 text-body-sm leading-relaxed text-stone-700">
                            <li><strong class="text-deep-950">Long-Term Bookings (&gt;{{ rules.refund_long_term_threshold_days }} days before lesson):</strong> 100% of lesson price if cancelled within {{ rules.refund_long_term_window_days }} days of payment; thereafter, standard short-term tiers apply.</li>
                            <li><strong class="text-deep-950">Short-Term Cancellation Tiers:</strong>
                                <span class="font-semibold text-deep-950">{{ rules.refund_short_term_full_days }}+ days</span> before lesson = <strong class="text-deep-950">100%</strong> lesson price;
                                <span class="font-semibold text-deep-950">{{ rules.refund_short_term_half_days }}–{{ rules.refund_short_term_full_days - 1 }} days</span> before lesson = <strong class="text-deep-950">{{ rules.refund_short_term_half_percent }}%</strong> lesson price;
                                <span class="font-semibold text-deep-950">&lt;{{ rules.refund_short_term_half_days }} days</span> before lesson = <strong class="text-deep-950">0%</strong> refund.
                            </li>
                            <li><strong class="text-deep-950">Instructor, Weather &amp; Reschedule-Decline Cancellations:</strong> 100% lesson-price refund when cancelled due to instructor unavailability, unsafe surf/weather conditions, or when a student declines a proposed reschedule.</li>
                            <li><strong class="text-deep-950">Gateway Window Exception:</strong> Refunds requested more than 30 days after payment route to manual SISA completion outside the automated gateway window.</li>
                        </ul>
                    </section>

                    <!-- Section 7 -->
                    <section id="digital-waivers" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">7. Digital Waivers &amp; Minor Parental Consent</h2>
                        <p class="mt-2 text-body-sm leading-relaxed text-stone-700">
                            Before any booking proceeds to payment, the participant (or parent/legal guardian for minors under 18) must read and electronically sign the <strong class="text-deep-950">SISA Surf Lesson Waiver, Liability, and Indemnity Agreement</strong>, declaring truthful medical fitness and emergency contact information.
                        </p>
                        <p class="mt-3 text-body-sm leading-relaxed text-stone-700">
                            Minors under 18 require an additional signed Parental/Guardian Consent form. Typed full legal names and electronic canvas signatures constitute binding consent and are archived securely for seven (7) years before automated disposal.
                        </p>
                    </section>

                    <!-- Section 8 -->
                    <section id="reviews-strikes" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">8. Reviews, Safety Incidents &amp; SISA 3-Strike Ladder</h2>
                        <ul class="mt-3 list-disc space-y-2 pl-5 text-body-sm leading-relaxed text-stone-700">
                            <li><strong class="text-deep-950">Public Reviews vs. Private Concerns:</strong> Public lesson ratings and comments never automatically trigger instructor strikes. Private safety concerns start as <strong class="text-deep-950">pending</strong> and take effect only after formal SISA review and confirmation.</li>
                            <li><strong class="text-deep-950">SISA V3 3-Strike Governance:</strong>
                                <ul class="mt-1.5 list-disc space-y-1 pl-5">
                                    <li><strong class="text-deep-950">1 Active Strike:</strong> Official warning plus a <strong class="text-deep-950">{{ formatPeso(rules.strike_fine_amount) }} fine</strong> payable directly to SISA outside the platform.</li>
                                    <li><strong class="text-deep-950">2 Active Strikes:</strong> <strong class="text-deep-950">{{ rules.strike_tier2_days }}-day suspension</strong> (hidden from booking, future confirmed lessons cancelled, requiring SISA reinstatement approval).</li>
                                    <li><strong class="text-deep-950">3+ Active Strikes:</strong> <strong class="text-deep-950">{{ rules.strike_tier3_days }}-day suspension</strong> plus external SISA ID confiscation.</li>
                                    <li><strong class="text-deep-950">Critical Incidents:</strong> Serious injuries or critical emergencies are escalated outside the platform directly to SISA and local authorities.</li>
                                </ul>
                            </li>
                            <li><strong class="text-deep-950">Appeals:</strong> Suspended instructors may submit one audited appeal per suspension through the platform for formal SISA administrative determination.</li>
                        </ul>
                    </section>

                    <!-- Section 9 -->
                    <section id="acceptable-use" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">9. Acceptable Use &amp; In-App Messaging</h2>
                        <p class="mt-2 text-body-sm leading-relaxed text-stone-700">
                            Users agree not to circumvent SISA safety locks, falsify participant ages or swimming ability, submit abusive or fraudulent bookings, upload forged certificates, harass other users in booking chat, or probe/interfere with platform security and rate limits. Violations may result in immediate account suspension or termination.
                        </p>
                    </section>

                    <!-- Section 10 -->
                    <section id="ocean-risk" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">10. Ocean Risk, Forecast Disclaimer &amp; Liability</h2>
                        <p class="mt-2 text-body-sm leading-relaxed text-stone-700">
                            Surfing is an outdoor ocean sport carrying inherent physical hazards including shifting tides, reef breaks, currents, marine life, and equipment impacts. Participation remains voluntary and conditioned on your signed digital waiver.
                        </p>
                        <p class="mt-3 text-body-sm leading-relaxed text-stone-700">
                            Tide, swell, and marine weather advisories displayed on SiaSurf (sourced from external forecast providers) are informational aids only and do not replace on-site visual ocean assessment by certified SISA instructors and beach marshals.
                        </p>
                    </section>

                    <!-- Section 11 -->
                    <section id="privacy-link" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">11. Privacy &amp; Data Protection</h2>
                        <p class="mt-2 text-body-sm leading-relaxed text-stone-700">
                            Your use of SiaSurf is equally governed by the <Link :href="route('legal.privacy')" class="font-medium text-lagoon-700 underline hover:text-deep-950">SiaSurf Privacy Policy</Link>, prepared under the Philippine Data Privacy Act of 2012 (RA 10173), detailing data collection, 7-year retention, security safeguards, and your rights as a data subject.
                        </p>
                    </section>

                    <!-- Section 12 -->
                    <section id="governing-law" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">12. Amendments, Governing Law &amp; Contact</h2>
                        <p class="mt-2 text-body-sm leading-relaxed text-stone-700">
                            SiaSurf and {{ legal.organization }} may update these Terms of Service to reflect operational, regulatory, or safety changes. Continued use after updates constitutes acceptance of the revised terms. These Terms are governed by the laws of the Republic of the Philippines.
                        </p>

                        <div class="mt-4 grid gap-3 rounded-xl border border-sand-200 bg-sand-50 p-4 text-body-sm text-stone-700 sm:grid-cols-2 sm:p-5">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-stone-500">Platform Governance</p>
                                <p class="mt-1 font-semibold text-deep-950">{{ legal.organization }}</p>
                                <p class="text-stone-700">{{ legal.dpo_name }}</p>
                            </div>
                            <div class="space-y-2 min-w-0">
                                <div class="flex items-start gap-2">
                                    <EnvelopeIcon class="mt-0.5 h-4 w-4 shrink-0 text-lagoon-700" aria-hidden="true" />
                                    <a :href="`mailto:${legal.contact_email}`" class="break-all font-medium text-lagoon-700 underline hover:text-deep-950">
                                        {{ legal.contact_email }}
                                    </a>
                                </div>
                                <div class="flex items-start gap-2">
                                    <PhoneIcon class="mt-0.5 h-4 w-4 shrink-0 text-lagoon-700" aria-hidden="true" />
                                    <span>{{ legal.contact_phone }}</span>
                                </div>
                                <div class="flex items-start gap-2">
                                    <MapPinIcon class="mt-0.5 h-4 w-4 shrink-0 text-lagoon-700" aria-hidden="true" />
                                    <span>{{ legal.office_address }}</span>
                                </div>
                            </div>
                        </div>
                    </section>
                </article>
            </div>
        </div>
    </AppSidebarLayout>
</template>
