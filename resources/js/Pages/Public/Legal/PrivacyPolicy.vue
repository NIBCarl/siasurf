<script setup lang="ts">
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    DocumentTextIcon,
    EnvelopeIcon,
    MapPinIcon,
    PhoneIcon,
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

defineProps<{
    legal: LegalMeta;
}>();

const sections = [
    { id: 'information-collected', label: '1. Information We Collect' },
    { id: 'how-we-use', label: '2. How We Use Information' },
    { id: 'legal-basis', label: '3. Legal Basis (RA 10173)' },
    { id: 'sharing-disclosure', label: '4. Sharing & Disclosure' },
    { id: 'qr-profiles', label: '5. QR Instructor Profiles' },
    { id: 'waivers-minors', label: '6. Waivers & Minors' },
    { id: 'retention', label: '7. Data Storage & Retention' },
    { id: 'security', label: '8. Data Security' },
    { id: 'user-responsibilities', label: '9. User Responsibilities' },
    { id: 'data-subject-rights', label: '10. Data Subject Rights' },
    { id: 'third-party-services', label: '11. Third-Party Services' },
    { id: 'cookies', label: '12. Cookies & Sessions' },
    { id: 'policy-changes', label: '13. Changes to Policy' },
    { id: 'contact', label: '14. Contact & DPO' },
    { id: 'acknowledgment', label: '15. Acknowledgment' },
];
</script>

<template>
    <Head title="Privacy Policy" />

    <AppSidebarLayout>
        <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8 lg:py-12">
            <!-- Hero Card -->
            <div class="mb-8 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="inline-flex items-center gap-2 rounded-full border border-lagoon-200 bg-lagoon-50 px-3 py-1 text-xs font-semibold text-lagoon-800">
                            <ShieldCheckIcon class="h-4 w-4 shrink-0" aria-hidden="true" />
                            <span>Data Privacy Act of 2012 (Republic Act No. 10173)</span>
                        </div>
                        <h1 class="mt-3 text-2xl font-extrabold tracking-tight text-deep-950 sm:text-3xl">
                            SiaSurf Privacy Policy
                        </h1>
                        <p class="mt-2 text-body-sm text-stone-600">
                            How SiaSurf and the {{ legal.organization }} collect, use, store, process, and safeguard personal information across the platform.
                        </p>
                    </div>

                    <div class="shrink-0">
                        <Link
                            :href="route('legal.terms')"
                            class="inline-flex min-h-[40px] items-center gap-2 rounded-control border border-sand-200 bg-sand-50 px-4 py-2 text-sm font-semibold text-deep-950 transition hover:bg-sand-100"
                        >
                            <DocumentTextIcon class="h-4 w-4 shrink-0 text-lagoon-700" aria-hidden="true" />
                            <span>Terms of Service</span>
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
                        <span class="font-semibold text-deep-950">Jurisdiction:</span>
                        Republic of the Philippines
                    </div>
                </div>
            </div>

            <div class="grid gap-8 lg:grid-cols-[240px_1fr] lg:items-start">
                <!-- Mobile Quick Jump -->
                <div class="lg:hidden">
                    <details class="rounded-card border border-sand-200 bg-white p-4 shadow-card">
                        <summary class="cursor-pointer text-sm font-bold text-deep-950">
                            Jump to Section (15 Sections)
                        </summary>
                        <nav aria-label="Privacy policy sections" class="mt-3 grid grid-cols-1 gap-1.5 sm:grid-cols-2">
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
                        <nav aria-label="Privacy policy contents" class="space-y-1">
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
                    <!-- Preamble -->
                    <div class="rounded-card border border-sand-200 bg-white p-6 text-body-sm leading-relaxed text-stone-700 shadow-card sm:p-8">
                        <p>
                            SiaSurf is committed to respecting and protecting the privacy of its users. This Privacy Policy explains how SiaSurf collects, uses, stores, processes, and protects personal information when tourists, surf instructors, and SISA administrators access or use the platform.
                        </p>
                        <p class="mt-3">
                            This policy is prepared in accordance with the principles of the Philippine Data Privacy Act of 2012 (Republic Act No. 10173), its Implementing Rules and Regulations, and applicable regulations of the National Privacy Commission (NPC). By accessing or using SiaSurf, you acknowledge that you have read and understood this Privacy Policy.
                        </p>
                    </div>

                    <!-- Section 1 -->
                    <section id="information-collected" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">1. Information We Collect</h2>
                        <p class="mt-2 text-body-sm leading-relaxed text-stone-700">
                            SiaSurf collects only the categories of personal information reasonably needed for each user&rsquo;s role and activities on the platform.
                        </p>

                        <div class="mt-5 space-y-5 text-body-sm leading-relaxed text-stone-700">
                            <div>
                                <h3 class="font-bold text-deep-950">1.1 Tourist Information</h3>
                                <p class="mt-1">When tourists register, complete a skill pre-assessment, or make a booking, the platform may collect:</p>
                                <ul class="mt-2 list-disc space-y-1.5 pl-5">
                                    <li>Full name</li>
                                    <li>Email address and account credentials</li>
                                    <li>Date of birth</li>
                                    <li>Contact information and country of residence</li>
                                    <li>Emergency contact details provided in the digital waiver</li>
                                    <li>Surfing experience, skill-level assessment responses, and surfboard ownership details</li>
                                    <li>Swimming ability confirmation and relevant medical safety declarations</li>
                                    <li>Booking dates, schedules, surf locations, pickup requests, and lesson preferences</li>
                                    <li>Digital waiver documents and electronic signatures</li>
                                    <li>Parent or legal guardian consent information and signatures, when applicable</li>
                                    <li>Payment method, amount, transaction references, and payment status</li>
                                    <li>Feedback, public reviews, and private safety incident reports submitted through the platform</li>
                                </ul>
                            </div>

                            <div>
                                <h3 class="font-bold text-deep-950">1.2 Surf Instructor Information</h3>
                                <p class="mt-1">For instructor registration, onboarding, and SISA verification, the platform may collect:</p>
                                <ul class="mt-2 list-disc space-y-1.5 pl-5">
                                    <li>Full name and contact information</li>
                                    <li>Account credentials</li>
                                    <li>SISA identification details, bio, base area, and designated surf zones</li>
                                    <li>Uploaded certificates and supporting documents, including WaSAR, BLS, ISA/SISA, and surf skills certificates</li>
                                    <li>Certified instructor level (Level 1, Level 2, or Level 3) and offered service types</li>
                                    <li>Weekly and date-specific availability and scheduling information</li>
                                    <li>Lesson rates and service records</li>
                                    <li>Booking history and session attendance records</li>
                                    <li>Daily earnings ledgers, cash service fee remittances, and payout records</li>
                                    <li>Feedback, complaints, strike ledger entries, suspension appeals, and incident records associated with the instructor</li>
                                </ul>
                            </div>

                            <div>
                                <h3 class="font-bold text-deep-950">1.3 SISA Administrator Information</h3>
                                <p class="mt-1">
                                    Authorized SISA administrators provide account and contact details required to access governance functions, verify instructor credentials, and maintain platform audit records.
                                </p>
                            </div>

                            <div>
                                <h3 class="font-bold text-deep-950">1.4 Technical and Usage Information</h3>
                                <p class="mt-1">When users access SiaSurf, the platform processes technical records necessary for operation, security, and troubleshooting, including:</p>
                                <ul class="mt-2 list-disc space-y-1.5 pl-5">
                                    <li>Login and account activity records</li>
                                    <li>System access logs and rate-limiting records</li>
                                    <li>Booking, waiver validation, and transaction activity logs</li>
                                    <li>Audit records of sensitive administrative, safety, and financial operations</li>
                                    <li>Diagnostic logs related to application errors, failed webhook/HTTP requests, and system performance</li>
                                </ul>
                                <p class="mt-3 rounded-lg border border-lagoon-200 bg-lagoon-50 px-4 py-2.5 text-xs font-medium text-lagoon-900">
                                    SiaSurf does not use background GPS tracking to monitor the real-time location of tourists or instructors.
                                </p>
                            </div>
                        </div>
                    </section>

                    <!-- Section 2 -->
                    <section id="how-we-use" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">2. How We Use Your Information</h2>
                        <p class="mt-2 text-body-sm leading-relaxed text-stone-700">
                            Personal information collected through SiaSurf is used solely for legitimate platform operations, safety enforcement, and regulatory compliance, including:
                        </p>
                        <ul class="mt-3 list-disc space-y-1.5 pl-5 text-body-sm leading-relaxed text-stone-700">
                            <li>Creating and managing student, instructor, and SISA administrator accounts.</li>
                            <li>Authenticating users and enforcing role-based access controls.</li>
                            <li>Verifying surf instructor identities and submitted safety certificates.</li>
                            <li>Displaying active verified instructor profiles, authorized surf zones, and verification status.</li>
                            <li>Generating permanent QR-linked instructor profiles for instant on-beach verification.</li>
                            <li>Conducting tourist skill-level and water-safety pre-assessments.</li>
                            <li>Matching tourists with instructors based on skill level, age eligibility, lesson type, surf spot difficulty, availability, and SISA safety ratios.</li>
                            <li>Processing, holding, confirming, rescheduling, and cancelling surfing lesson bookings.</li>
                            <li>Calculating standardized lesson fees and the 7% platform service fee.</li>
                            <li>Recording GCash and Cash on Hand payment transactions, refunds, and settlement statuses.</li>
                            <li>Managing instructor daily earnings ledgers, cash fee remittances, and in-person SISA payouts.</li>
                            <li>Generating and storing legally binding digital waivers and parent or legal guardian consent records.</li>
                            <li>Providing booking-scoped communication between matched students and instructors via in-app chat.</li>
                            <li>Delivering transactional email and in-app booking, safety, and system notifications.</li>
                            <li>Recording public lesson reviews and private safety incident reports for SISA review.</li>
                            <li>Supporting SISA administrators in monitoring safety compliance, administering strikes/fines/suspensions, and reviewing appeals.</li>
                            <li>Generating operational dashboards and authorized LGU/SISA compliance reports.</li>
                            <li>Maintaining platform security, preventing booking abuse, and investigating technical issues.</li>
                            <li>Complying with applicable Philippine laws and regulatory obligations.</li>
                        </ul>
                        <p class="mt-4 text-body-sm leading-relaxed text-stone-700">
                            Personal information will not be used for purposes unrelated to the operation of SiaSurf unless you are notified and an appropriate lawful basis exists under RA 10173.
                        </p>
                    </section>

                    <!-- Section 3 -->
                    <section id="legal-basis" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">3. Legal Basis for Processing</h2>
                        <p class="mt-2 text-body-sm leading-relaxed text-stone-700">
                            SiaSurf processes personal information in accordance with the lawful criteria under the Data Privacy Act of 2012 (Sections 12 and 13), depending on the context:
                        </p>
                        <ul class="mt-3 list-disc space-y-2 pl-5 text-body-sm leading-relaxed text-stone-700">
                            <li><strong class="text-deep-950">Consent:</strong> When a user voluntarily agrees to account terms, submits sensitive health/safety declarations in a waiver, or provides parent/guardian authorization for a minor.</li>
                            <li><strong class="text-deep-950">Contractual Necessity:</strong> When processing is necessary to fulfill a surf lesson booking, instructor onboarding, payment recording, refund evaluation, or payout settlement requested by the user.</li>
                            <li><strong class="text-deep-950">Legal Obligation:</strong> When retention or disclosure is required to comply with local government (LGU) tourism ordinances, SISA safety oversight, tax/accounting requirements, or valid legal processes.</li>
                            <li><strong class="text-deep-950">Legitimate Interests:</strong> When processing is necessary to enforce ocean safety rules, verify instructor qualifications, prevent fraud or platform abuse, and maintain system integrity without overriding the user&rsquo;s fundamental rights.</li>
                        </ul>
                        <p class="mt-4 text-body-sm leading-relaxed text-stone-700">
                            Where consent serves as the legal basis, users may withdraw consent at any time, subject to statutory retention obligations and the inability to continue using platform services that depend on that information.
                        </p>
                    </section>

                    <!-- Section 4 -->
                    <section id="sharing-disclosure" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">4. Sharing and Disclosure of Information</h2>
                        <p class="mt-2 text-body-sm leading-relaxed text-stone-700">
                            SiaSurf does not sell, rent, or trade users&rsquo; personal information to third parties. Information is shared strictly on a need-to-know basis for legitimate platform operations:
                        </p>
                        <ul class="mt-3 list-disc space-y-2 pl-5 text-body-sm leading-relaxed text-stone-700">
                            <li><strong class="text-deep-950">SISA Administrators:</strong> Access to instructor verification files, certificate records, booking manifests, safety incident reports, strike/appeal records, and financial settlement ledgers required for governance.</li>
                            <li><strong class="text-deep-950">Assigned Surf Instructors:</strong> Access to relevant booking schedule details, student name, skill level, age group/consent clearance, hotel pickup note (if selected), and booking-scoped chat needed to conduct a lesson safely.</li>
                            <li><strong class="text-deep-950">Assigned Tourists:</strong> Access to an instructor&rsquo;s public profile, verification badge, SISA level, authorized surf zones, public reviews, and applicable service rates.</li>
                            <li><strong class="text-deep-950">Payment Service Providers:</strong> Necessary transaction details transmitted when paying via integrated online payment gateways (Xendit or PayMongo for GCash).</li>
                            <li><strong class="text-deep-950">Technical &amp; Messaging Providers:</strong> Cloud hosting, database, and transactional email delivery providers operating solely to keep SiaSurf running securely.</li>
                            <li><strong class="text-deep-950">Government Authorities:</strong> Disclosure when mandated by law, local regulation, emergency medical response, or valid court/regulatory order.</li>
                        </ul>
                    </section>

                    <!-- Section 5 -->
                    <section id="qr-profiles" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">5. QR-Linked Instructor Profiles</h2>
                        <p class="mt-2 text-body-sm leading-relaxed text-stone-700">
                            SiaSurf issues permanent QR codes that allow tourists and LGU/SISA personnel to scan and verify an instructor&rsquo;s live status before entering the water. Scanning a QR code displays verification information including:
                        </p>
                        <ul class="mt-3 list-disc space-y-1.5 pl-5 text-body-sm leading-relaxed text-stone-700">
                            <li>Instructor name and profile summary</li>
                            <li>Live SISA verification or suspension status</li>
                            <li>Certified instructor level (Level 1, 2, or 3)</li>
                            <li>Applicable service types and hourly rates</li>
                        </ul>
                        <p class="mt-3 text-body-sm leading-relaxed text-stone-700">
                            Public QR verification pages never expose uploaded private identity/certificate files, account credentials, financial ledgers, or payout records. Displayed verification reflects live SISA administrative records.
                        </p>
                    </section>

                    <!-- Section 6 -->
                    <section id="waivers-minors" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">6. Digital Waivers and Information About Minors</h2>
                        <p class="mt-2 text-body-sm leading-relaxed text-stone-700">
                            SiaSurf requires signed digital waivers to document a tourist&rsquo;s awareness of inherent ocean hazards, medical fitness declarations, and emergency contact details prior to lesson confirmation.
                        </p>
                        <ul class="mt-3 list-disc space-y-1.5 pl-5 text-body-sm leading-relaxed text-stone-700">
                            <li>Children below five (5) years of age are not permitted to book surfing lessons on the platform.</li>
                            <li>Tourists aged five (5) to twelve (12) require a SISA Level 3 instructor under mandatory safety rules.</li>
                            <li>For all tourists under eighteen (18) years of age, separate parent or legal guardian consent and electronic signature are required before payment or confirmation.</li>
                        </ul>
                        <p class="mt-3 text-body-sm leading-relaxed text-stone-700">
                            Parents and legal guardians must ensure that all submitted age, health, emergency contact, and authorization records for a minor are truthful and complete.
                        </p>
                    </section>

                    <!-- Section 7 -->
                    <section id="retention" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">7. Data Storage and Retention</h2>
                        <p class="mt-2 text-body-sm leading-relaxed text-stone-700">
                            SiaSurf retains personal and legal records only for as long as necessary to fulfill stated operational, safety, and legal accountability purposes:
                        </p>
                        <ul class="mt-3 list-disc space-y-1.5 pl-5 text-body-sm leading-relaxed text-stone-700">
                            <li><strong class="text-deep-950">Booking records:</strong> Retained for seven (7) years.</li>
                            <li><strong class="text-deep-950">Payment and financial ledger records:</strong> Retained for seven (7) years as an audit trail for transactions, fees, refunds, and instructor settlements.</li>
                            <li><strong class="text-deep-950">Digital waivers and parental consent records:</strong> Retained for seven (7) years, after which expired waiver documents are permanently purged through automated scheduled cleanup.</li>
                            <li><strong class="text-deep-950">Instructor certificates:</strong> Retained while the instructor account remains registered on the platform and during required regulatory audit windows.</li>
                            <li><strong class="text-deep-950">In-app booking chat history:</strong> Retained for the lifecycle of the associated booking record.</li>
                        </ul>
                        <p class="mt-3 text-body-sm leading-relaxed text-stone-700">
                            Upon expiration of the applicable retention schedule, records are securely deleted, purged, or anonymized unless continued retention is mandated by law or an unresolved safety dispute.
                        </p>
                    </section>

                    <!-- Section 8 -->
                    <section id="security" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">8. Data Security</h2>
                        <p class="mt-2 text-body-sm leading-relaxed text-stone-700">
                            SiaSurf applies organizational, procedural, and technical safeguards designed to protect personal information against unauthorized access, disclosure, alteration, or loss, including:
                        </p>
                        <ul class="mt-3 list-disc space-y-1.5 pl-5 text-body-sm leading-relaxed text-stone-700">
                            <li>HTTPS / TLS transport encryption for data transmitted between your browser and our deployed servers.</li>
                            <li>Cryptographic password hashing, verification requirements, brute-force login throttling, and strict role-based access controls (Student, Instructor, SISA Admin).</li>
                            <li>Application-layer defenses against cross-site scripting (XSS), SQL injection (parameterized ORM queries), cross-site request forgery (CSRF), and unvalidated request payloads.</li>
                            <li>Server-side MIME type and file size validation for uploaded instructor certificates, plus private storage outside the public web root for sensitive waiver and certificate files.</li>
                            <li>Signature verification on payment gateway webhooks (Xendit / PayMongo) and audit logging for logins, safety validations, payments, refunds, strikes, and administrative actions.</li>
                            <li>Strict participant authorization restricting booking chat and booking details to matched parties and authorized SISA administrators.</li>
                        </ul>
                        <p class="mt-3 text-body-sm leading-relaxed text-stone-700">
                            While we continuously strengthen these protections, no internet-based platform or storage mechanism can guarantee absolute security.
                        </p>
                    </section>

                    <!-- Section 9 -->
                    <section id="user-responsibilities" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">9. User Responsibilities</h2>
                        <p class="mt-2 text-body-sm leading-relaxed text-stone-700">
                            Users play a vital role in keeping their accounts and information safe by:
                        </p>
                        <ul class="mt-3 list-disc space-y-1.5 pl-5 text-body-sm leading-relaxed text-stone-700">
                            <li>Keeping account passwords strictly confidential and choosing strong, unique credentials.</li>
                            <li>Providing truthful, up-to-date identity, age, swimming ability, and emergency contact details.</li>
                            <li>Avoiding shared or public devices for sensitive account, waiver, or payment actions whenever possible, and logging out after use.</li>
                            <li>Promptly reporting suspected unauthorized account access or suspicious messages to SISA administrators.</li>
                            <li>Not attempting to access, scrape, or alter another user&rsquo;s account, private bookings, or confidential documents.</li>
                        </ul>
                    </section>

                    <!-- Section 10 -->
                    <section id="data-subject-rights" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">10. Rights of Data Subjects</h2>
                        <p class="mt-2 text-body-sm leading-relaxed text-stone-700">
                            Under the Philippine Data Privacy Act of 2012 (RA 10173), you may exercise the following data privacy rights subject to identity verification and statutory limitations:
                        </p>
                        <ul class="mt-3 list-disc space-y-1.5 pl-5 text-body-sm leading-relaxed text-stone-700">
                            <li><strong class="text-deep-950">Right to be informed:</strong> To know how your personal information is collected, processed, stored, and shared.</li>
                            <li><strong class="text-deep-950">Right to access:</strong> To request reasonable access to personal information SiaSurf holds about you.</li>
                            <li><strong class="text-deep-950">Right to object:</strong> To object to certain processing activities where permitted by law.</li>
                            <li><strong class="text-deep-950">Right to erasure or blocking:</strong> To request deletion, blocking, or suspension of personal data when legally justified and not restricted by the 7-year safety/financial retention rule.</li>
                            <li><strong class="text-deep-950">Right to rectification:</strong> To request correction of inaccurate, incomplete, or outdated personal details.</li>
                            <li><strong class="text-deep-950">Right to data portability:</strong> To obtain an electronic copy of eligible personal data provided to the platform.</li>
                            <li><strong class="text-deep-950">Right to file a complaint:</strong> To escalate unresolved privacy concerns to SISA&rsquo;s Data Protection Officer or the National Privacy Commission (NPC).</li>
                            <li><strong class="text-deep-950">Right to damages:</strong> To seek indemnification as provided by applicable Philippine law when harmed by unlawful processing.</li>
                        </ul>
                        <p class="mt-3 text-body-sm leading-relaxed text-stone-700">
                            To submit a privacy request, contact SISA using the details in Section 14. Requests may require proof of identity and cannot override mandatory 7-year waiver, incident, or financial audit retention laws.
                        </p>
                    </section>

                    <!-- Section 11 -->
                    <section id="third-party-services" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">11. Third-Party Services and External Links</h2>
                        <p class="mt-2 text-body-sm leading-relaxed text-stone-700">
                            SiaSurf integrates select third-party services to support platform features, including online GCash payment gateways (Xendit and PayMongo), marine weather and tide forecast feeds (Stormglass and Open-Meteo), transactional email delivery (Resend / SMTP), OpenStreetMap / Leaflet mapping tiles, and cloud infrastructure hosting.
                        </p>
                        <p class="mt-3 text-body-sm leading-relaxed text-stone-700">
                            Those external providers operate under their own privacy policies and security terms. SiaSurf is not responsible for the independent privacy practices of external sites linked from or integrated with the platform.
                        </p>
                    </section>

                    <!-- Section 12 -->
                    <section id="cookies" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">12. Cookies and Similar Technologies</h2>
                        <p class="mt-2 text-body-sm leading-relaxed text-stone-700">
                            SiaSurf uses strictly necessary session and security cookies (such as encrypted Laravel session and CSRF tokens) to authenticate signed-in accounts, protect forms against cross-site request forgery, and preserve your booking checkout flow.
                        </p>
                        <p class="mt-3 text-body-sm leading-relaxed text-stone-700">
                            You may manage or clear cookies in your browser settings; however, blocking essential session cookies will prevent login, booking, and waiver submission from functioning properly.
                        </p>
                    </section>

                    <!-- Section 13 -->
                    <section id="policy-changes" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">13. Changes to This Privacy Policy</h2>
                        <p class="mt-2 text-body-sm leading-relaxed text-stone-700">
                            SiaSurf may update this Privacy Policy periodically to reflect improvements to platform capabilities, SISA safety policies, operational partners, or applicable legal requirements. Updates take effect when published on this page alongside the revised Last Updated date.
                        </p>
                    </section>

                    <!-- Section 14 -->
                    <section id="contact" class="scroll-mt-24 rounded-card border border-sand-200 bg-white p-6 shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-deep-950 sm:text-xl">14. Contact Information and Privacy Concerns</h2>
                        <p class="mt-2 text-body-sm leading-relaxed text-stone-700">
                            For questions, data access or correction requests, or privacy complaints, contact our designated Personal Information Controller / Data Protection Officer:
                        </p>

                        <div class="mt-4 grid gap-3 rounded-xl border border-sand-200 bg-sand-50 p-4 text-body-sm text-stone-700 sm:grid-cols-2 sm:p-5">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-stone-500">Organization &amp; Officer</p>
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

                        <p class="mt-4 text-body-sm leading-relaxed text-stone-700">
                            Users may also lodge complaints directly with the <strong class="text-deep-950">National Privacy Commission (NPC)</strong> of the Philippines through its official public channels regarding any unresolved data privacy concern.
                        </p>
                    </section>

                    <!-- Section 15 -->
                    <section id="acknowledgment" class="scroll-mt-24 rounded-card border border-sand-200 bg-deep-950 p-6 text-white shadow-card sm:p-8">
                        <h2 class="text-lg font-bold text-white sm:text-xl">15. Acknowledgment</h2>
                        <p class="mt-2 text-body-sm leading-relaxed text-lagoon-100/90">
                            By creating an account or using SiaSurf, you acknowledge that you have read, understood, and been informed of how your personal information is collected, processed, retained, and protected across the platform.
                        </p>
                        <p class="mt-4 text-xs font-semibold tracking-wide text-lagoon-300">
                            SiaSurf — Supporting Verified Surf Instructors and Safer Booking Experiences in Siargao.
                        </p>
                    </section>
                </article>
            </div>
        </div>
    </AppSidebarLayout>
</template>
