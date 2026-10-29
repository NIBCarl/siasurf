# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Stack

Laravel 12 (PHP 8.2+) + Vue 3 + Inertia.js + Vite + TypeScript + Tailwind CSS 3 + MySQL 8 (XAMPP) + Redis (queue/cache) + Laravel Reverb (WebSockets) + AWS S3 (private buckets, signed URLs) + PayMongo GCash. Existing scaffold is the source of truth; `composer.json:laravel/framework ^12.0`, `package.json:vue ^3.4.0/@inertiajs/vue3 ^2.0.0/vite ^7.0.7/tailwindcss ^3.2.1`.

## Users

**Primary: Student / Tourist** â€” first-time or visiting surfer on Siargao beach (mobile, walk-in via QR scan), job: find a SISA-verified instructor for their skill/age/date/budget, complete safety validation, sign waiver (parental consent if <18), pay, and attend a monitored session. Success = verified match + safe lesson at locked price. (Confirmed 2026-08-24: "Student/Tourist (Recommended)" as design driver)

**Secondary: Verified Surf Instructor** â€” SISA-certified local (Level 1/2/3), job: maintain profile/availability (morning/afternoon weekly + specific dates), accept/decline bookings, start/monitor sessions with countdown timer, log attendance, view earnings. Account restricted until admin verification; hidden after 3 strikes (1-month suspension).

**Tertiary: Admin (SISA/LGU)** â€” operator verifying instructors/certificates, managing status/suspensions, logging safety incidents with strike counting, monitoring real-time sessions, viewing analytics and exporting reports (Excel/PDF). Operates via desktop dashboard with real-time WebSocket feed.

## Product Purpose

Centralize Siargao's fragmented manual surf-tourism workflow into one verified, safety-governed booking system. Digitalizes instructor verification (cert upload â†’ admin review â†’ permanent QR), standardizes booking (search â†’ safety validation â†’ waiver â†’ payment â†’ confirmation), and enforces non-overridable safety rules with real-time session/incident monitoring. Success = every lesson is matched by level/age/spot capability, paid at standardized rates, documented with signed waivers, and auditable via 7-year S3 storage.

## Positioning

The only Siargao platform where instructor identity, safety eligibility, and session accountability are cryptographically and procedurally enforced in the booking flow â€” not claimed as marketing copy. Permanent static QR links to verified profile with level/strike history; `SafetyService` throws `SafetyViolationException` before booking creation and logs to `safety_audit_log` with **no admin override**; live session monitoring with countdown and incident-triggered 3-strike observer. A generic booking marketplace cannot copy this without SISA-verified credentials and the 6 hard-coded safety invariants.

## Operating Context

Workflows: public search with filters (location/availability/price/level, MySQL FULLTEXT) â†’ instructor profile â†’ booking flow (skill/age/date/time_period `morning|afternoon`/surf_spot/surfboard ownership â†’ safety validation â†’ liability waiver/parental consent canvas signature â†’ PDF via `barryvdh/laravel-dompdf` â†’ S3 â†’ payment GCash via PayMongo intent/checkout/webhook signature or Cash on Hand marked within 24h â†’ 15-min hold, confirmation email + WebSocket `BookingCreated/Confirmed` â†’ instructor availability auto-block â†’ session start/countdown/attendance â†’ review email 2h post-lesson (queue) â†’ rating/comment + incident logging if needed.

Environments: beach walk-in QR scan (`html5-qrcode` client, `simplesoftwareio/simple-qrcode` server) â†’ fast-track booking; mobile-first for tourists, desktop bento dashboard for admins; offline queue for email/PDF generation via Redis.

Tools/documents on hand: PayMongo sandbox, AWS S3 signed URLs (30-min expiry), virus scan on PDF uploads (PDF only, max 10MB), WebSocket channels `instructor.{id}/student.{id}/admin`.

## Capabilities and Constraints

**Confirmed capabilities:** instructor registration + certificate management (types: `bls/wasar/surf_skill/isa/sisa`, statuses `pending/verified/expired`), permanent QR generation once on approval, status lifecycle `active/suspended/inactive` + strike history, weekly + date-specific availability with auto-block on confirmed slots + 1-hour school enforcement and non-overlap rule, booking statuses `pending/confirmed/completed/cancelled`, payments `gcash/cash` with statuses `pending/completed/failed/refunded`, waivers `liability/parental_consent` with base64 signature, reviews 20-char min, 1 per booking, 24h edit, incidents `injury/near_miss/rule_violation` with `minor/major/critical` â†’ 1/2/3 strikes via `SafetyIncidentObserver`.

**Post-session feedback, strikes, and appeals (implemented):** one student-facing feedback hub separates public reviews (rating/comment/photo/positive tags; **0 strikes**) from private concerns. A concern starts `pending`; SISA confirms or rejects it. Confirmed Minor/Major/Critical adds 1/2/3 strikes to an append-only `instructor_strikes` ledger. Three active strikes trigger an automatic 30-day suspension with a stored reason, hide the instructor from search, and cancel future bookings. The suspended instructor sees the reason on `/instructor/suspended` and may submit one appeal; SISA can approve (lifting the suspension and revoking selected strikes) or reject with a written response. SISA can also suspend manually with a required reason and duration. Public reviews never directly create strikes.


**Hard constraints (NEVER override):**
- BR-3.1 L1: max 2 students, beginners only
- BR-3.2 L2: 1-on-1 only
- BR-3.3 L3: max 5 students
- BR-3.4 Age 5-12: requires 2 instructors
- BR-3.5 Beginners: safe zones only (hide advanced spots)
- BR-3.6 Cert must match lesson difficulty

**Pricing:** L1-2 min â‚±600/hr (standard 2hr â‚±1,200), L3/ISA â‚±1,500-2,000, add-ons video +â‚±500 / photo +â‚±800, weekly -10% / monthly -20%, booking held 15min during GCash, cash marked 24h.

**Technical constraints:** `$fillable` whitelist, enum casts, Spatie roles `student/instructor/admin`, Breeze auth, `EnsureInstructorIsVerified/NotSuspended/EnsureWaiverSigned` middleware, rate limit login 5/min, API 60/min, payments 10/min, audit logging for login/payment/status change, S3 encryption at rest for medical/emergency contacts, security headers, no raw SQL concatenation.

**Explicitly undecided:** none â€” stack and brand locked per 2026-08-24 confirmation to preserve existing (`Preserve existing`).

## Brand Commitments

- Name: SiaSurf â€” "Smart Booking and Instructor Verification Platform with Real-time Safety Integration and Monitoring", Siargao Island, Philippines, official SISA integration.
- Existing assets to preserve (do not reinvent): logo `public/assets/images/siargao_cloud9_sunset.png`, hero `public/images/hero_siargao.png`, spot images `spot_cloud9/quicksilver/jacking_horse.png`, instructor samples `instructor_male/female.png`, wave SVG divider in `Welcome.vue:122-125`.
- Design system in `tailwind.config.js:8-78`: `fontFamily sans Outfit + serif Cinzel`, colors `ocean 50-900 #0ea5e9 base`, `reef #14b8a6`, `sand #c4ab7c`, `wave #818cf8`; shadows `ocean/reef/sand/wave` + `glow-ocean/reef`; animations `wave/float/pulse-slow/shimmer` + gradients `gradient-ocean/reef/sand/wave`+`shimmer`; plugin `@tailwindcss/forms`.
- Voice/tone: verified, safety-governed, ocean-professional (teal/ocean authority, sandy neutrals, not terracotta/purple SaaS default); `frontend-design` ocean surf premium per `AGENTS.md:Phase10`.
- No binding aesthetic reversal requested; future work inherits this world (use `document` to record, not replace).

## Evidence on Hand

- Repo evidence: `tailwind.config.js` (full theme), `resources/js/Pages/Welcome.vue:1-368` (hero/nav/stats/features/spots/gallery/CTA/footer + wave decoration), `resources/js/Pages/Admin/Dashboard.vue:1-305` (bento stats Bento: total bookings/instructors/revenue/incidents/live sessions + RevenueChart/BookingDistribution/PopularSpots/TopInstructors/ActivityFeed + ongoing sessions monitor), `resources/js/Components/Atoms/BaseButton.vue`, `resources/js/Components/Molecules/InstructorCard.vue`, `resources/js/Pages/Booking/*` (Create/Waiver/ParentalConsent/Payment/Confirmation), `resources/js/Layouts/GuestLayout.vue:8-26` cinematic split, `app/Enums/*.php` (SkillLevel:beginner/intermediate/advanced, InstructorLevel 1-3, BookingStatus, etc.), `resources/views/pdfs/{waiver,parental_consent,receipt}.blade.php`, `AGENTS.md`, `prd.md` FR-1..5, `tech_stack.md`, `.opencode/context.md`.
- Placeholder stats in `Welcome.vue:133-148` (`500+ Verified Instructors`, `12k+ Safe Sessions`, `PayMongo`) are illustrative and must be replaced with real DB counts before launch; do not treat as verified claims.
- No fabricated testimonials/benchmarks/customers added; instructor names `Junrey 'Bogs' Capistrano` / `Ana 'Maui' San Jose` in `Welcome.vue:254-287` are demonstration data and labeled synthetic until replaced with real verified profiles.

## Product Principles

1. **Verification before visibility** â€” no instructor surfaces in search or QR without SISA-verified status and valid certificates; trust is enforced in query scopes, not copy.
2. **Safety as code, not policy** â€” the 6 BR-3.* rules run in `SafetyService` before booking persistence; a blocked booking shows a human-readable `SafetyViolationException` and an audit log, never an admin bypass toggle.
3. **Student success = safe match at a fair price** â€” task completion (find â†’ book â†’ pay â†’ surf monitored) outranks expression; pricing minimums protect both tourist and instructor wages, and the booking flow never hides eligibility errors.
4. **Live accountability** â€” every session is time-bound, countable, and observable (countdown, active monitor, incidentâ†’strike) so safety is not a one-time check.
5. **Siargao-native system** â€” offline-tolerant schedules, walk-in QR, mobile-first beach usage, and LGU/SISA audit exports (Excel/PDF 7-year retention) are core, not edge cases.

## Accessibility & Inclusion

Mobile-first responsive (tourist on beach), WCAG 2.1 AA target via Headless UI + keyboard/focus rings in `BaseButton.vue:15`; semantic HTML, `alt` on hero/spot images, Inter/Outfit legibility, 44px touch targets, error/id states from `harden` playbook. Age gate handles minors with parental consent flow and emergency/medical encryption. No platform-specific native requirement; `web` only.

