# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Stack

Laravel 12 (PHP 8.2+) + Vue 3 + Inertia.js + Vite + TypeScript + Tailwind CSS 3 + MySQL 8 (XAMPP) + `database` queue + `file` cache/session + `local` disk storage + Laravel Reverb (WebSockets) + Xendit GCash (primary, with PayMongo & `MockPaymentService` fallbacks via `PaymentServiceInterface`). Redis and AWS S3 are planned, not currently wired. Existing scaffold is the source of truth; `composer.json:laravel/framework ^12.0`, `package.json:vue ^3.4.0/@inertiajs/vue3 ^2.0.0/vite ^7.0.7/tailwindcss ^3.2.1`.

## Users

**Primary: Student / Tourist** — first-time or visiting surfer on Siargao beach (mobile, walk-in via QR scan), job: find a SISA-verified instructor for their skill/age/date/budget, complete safety validation, sign waiver (parental consent if <18), pay, and attend a monitored session. Success = verified match + safe lesson at locked price. (Confirmed 2026-08-24: "Student/Tourist (Recommended)" as design driver)

**Secondary: Verified Surf Instructor** — SISA-certified local (Level 1/2/3), job: maintain profile/availability (morning/afternoon weekly + specific dates), accept/decline bookings, start/monitor sessions with countdown timer, log attendance, view earnings. Account restricted until admin verification; hidden from booking at 2 strikes (7-day suspension) and 3+ strikes (30-day suspension + SISA ID confiscation outside system).

**Tertiary: Admin (SISA/LGU)** — operator verifying instructors/certificates, managing status/suspensions, logging safety incidents with strike counting, monitoring real-time sessions, viewing analytics and exporting reports (Excel/PDF). Operates via desktop dashboard with real-time WebSocket feed.

## Product Purpose

Centralize Siargao's fragmented manual surf-tourism workflow into one verified, safety-governed booking system. Digitalizes instructor verification (cert upload → admin review → permanent QR), standardizes booking (search → safety validation → waiver → payment → confirmation), and enforces non-overridable safety rules with real-time session/incident monitoring. Success = every lesson is matched by level/age/spot capability, paid at standardized rates, documented with signed waivers, and auditable via 7-year local document retention (`CleanupExpiredWaivers`).

## Positioning

The only Siargao platform where instructor identity, safety eligibility, and session accountability are cryptographically and procedurally enforced in the booking flow — not claimed as marketing copy. Permanent static QR links to verified profile with level/strike history; `SafetyService` throws `SafetyViolationException` before booking creation and logs to `safety_validation_logs` with **no admin override**; live session monitoring with countdown and incident-triggered V3 3-strike observer (`SafetyIncidentObserver` → `StrikeService`). A generic booking marketplace cannot copy this without SISA-verified credentials and the 6 hard-coded safety invariants.

## Operating Context

Workflows: public search with filters (location/availability/price/level, MySQL FULLTEXT) → instructor profile → booking flow (skill/age/date/time_period `morning|afternoon`/surf_spot/surfboard ownership → safety validation → liability waiver/parental consent canvas signature → PDF via `barryvdh/laravel-dompdf` → `local` disk storage → payment GCash via Xendit (primary) / PayMongo / Mock through `PaymentServiceInterface` with webhook verification, or Cash on Hand marked within 24h → 15-min checkout/payment hold, confirmation email + WebSocket `BookingCreated`/`BookingConfirmed` → instructor availability auto-block → session start/countdown/attendance → review email 2h post-lesson via `database` queue → rating/comment + private concern reporting if needed).

Environments: beach walk-in QR scan (server-generated SVG via `simplesoftwareio/simple-qrcode` scanned by standard mobile cameras to `/qr/{id}`) → fast-track booking; mobile-first for tourists, desktop bento dashboard for admins; Laravel `database` queue for email/PDF jobs (no offline PWA/Redis queue).

Tools/documents on hand: Xendit (active) / PayMongo / `MockPaymentService` payment providers, `local` private disk storage (no S3 signed URLs currently wired), `FileScanService` (real MIME verification for PDF/JPG/PNG, max 10MB, plus optional ClamAV `clamdscan` when `VIRUS_SCAN_ENABLED=true`, disabled by default), WebSocket channels `instructor.{id}`, `student.{id}`, `admin.notifications`, `booking.{bookingId}`.

## Capabilities and Constraints

**Confirmed capabilities:** instructor registration + certificate management (types: `bls/wasar/surf_skill/isa/sisa`, statuses `pending_verification/verified/rejected/expired`), permanent QR generation on activation/verification, status lifecycle `pending_verification/active/suspended/inactive/rejected` + strike history, weekly + date-specific availability with auto-block on confirmed slots + non-overlap rule, 11 booking statuses (`reserved/pending/awaiting_payment/confirmed/in_progress/completed/declined/cancelled/payment_timeout/expired/no_show`), payments (`gcash/cash/paymaya`) with 9 statuses (`pending/completed/underpaid/failed/refund_requested/refund_processing/refunded/pending_cash/pending_verification`), waivers (`liability/parental_consent`) with base64 signature, reviews 20-char min, 1 per booking, 24h edit, incidents (`injury/near_miss/rule_violation`) with `Minor = 1`, `Major = 2`, `Critical = 0` (handled externally by SISA/authorities) and canonical admin decisions via `IncidentOutcome` (`no_strike/1_strike/2_strikes/3_strikes/critical`) processed by `SafetyIncidentObserver` → `StrikeService`.

**Post-session feedback, strikes, and appeals (implemented, V3):** one student-facing feedback hub (`Student/Feedback/Create.vue`) separates public reviews (rating/comment/photo/positive tags; **0 strikes**) from private concerns. A private concern starts `pending` (`severity = null`, `strike_weight = 0`); SISA confirms or dismisses it (`decision_reason` + private `admin_note`). Confirmed strikes append to the `instructor_strikes` ledger and apply the V3 ladder: **1 active strike** = official warning + ₱500 fine (`instructor_fines`, `due`, collected outside system); **2 active strikes** = 7-day (1-week) suspension (hidden from booking, future bookings cancelled, reinstatement requires SISA approval and preserves the 2 active strikes); **3+ active strikes** = 30-day (1-month) suspension + SISA ID confiscation outside system (reinstatement marks served strikes as `served`). **Critical** incidents record `0` in-system strikes (`handled_externally = true`) for SISA/authorities. The suspended instructor sees the tier/reason on `/instructor/suspended` and may submit one appeal per suspension; SISA can approve or reject. Revoking strikes voids unpaid `due` fines and reconciles active suspensions. Public reviews never create strikes.

**Hard constraints (NEVER override):**
- BR-3.1 L1: max 1 student, beginners only
- BR-3.2 L2: 1 beginner OR up to 2 intermediate students
- BR-3.3 L3: 1–3 students of the same skill level
- BR-3.4 Age < 5 rejected; Age 5–12 requires a Level 3 instructor (`< 18` requires parental consent)
- BR-3.5 Surf spot difficulty must not exceed surfer's skill level (group capped by least-experienced participant)
- BR-3.6 Instructor certification & service type (`Teaching`/`Guiding`/`Coaching`) must match lesson difficulty

**Pricing:** service-based hourly rates (`Teaching` ₱600–₱1,000/hr, `Guiding` ₱800–₱1,500/hr, `Coaching` ₱1,500–₱2,000/hr), standard 2-hour session, 7% non-refundable platform service fee (`BR-PRC-03`), booking held 15min during checkout/payment, cash marked within 24h. *(Legacy `PricingService` constants `ADD_ONS` video +₱500 / photo +₱800, `WEEKLY_DISCOUNT` 10%, and `MONTHLY_DISCOUNT` 20% exist in code but are unwired in the booking UI.)*

**Technical constraints:** `$fillable` whitelist, enum casts, Spatie roles `student/instructor/admin`, Breeze auth, `EnsureInstructorIsVerified`/`EnsureInstructorNotSuspended`/`EnsureWaiverSigned`/`EnsureInstructorLifecycleState` middleware, rate limit `login` 5/min, `public-api` 60/min, `payments` 10/min, `bookings` 20/min, `chat` 30/min, audit logging for login/payment/status change, local private disk storage currently active (AWS S3 encryption/signed URLs planned, not yet wired), `SecurityHeaders` middleware implemented in `app/Http/Middleware/SecurityHeaders.php` (pending registration in `bootstrap/app.php`), no raw SQL concatenation.

**Explicitly undecided:** none — stack and brand locked per 2026-08-24 confirmation to preserve existing (`Preserve existing`).

## Brand Commitments

- Name: SiaSurf — "Smart Booking and Instructor Verification Platform with Real-time Safety Integration and Monitoring", Siargao Island, Philippines, official SISA integration.
- Existing assets to preserve (do not reinvent): logo `public/assets/images/siargao_cloud9_sunset.png`, hero `public/images/hero_siargao.png`, spot images `spot_cloud9/quicksilver/jacking_horse.png`, instructor samples `instructor_male/female.png`, wave SVG divider in `Welcome.vue:122-125`.
- Design system in `tailwind.config.js:8-78`: `fontFamily sans Outfit + serif Cinzel`, colors `ocean 50-900 #0ea5e9 base`, `reef #14b8a6`, `sand #c4ab7c`, `wave #818cf8`; shadows `ocean/reef/sand/wave` + `glow-ocean/reef`; animations `wave/float/pulse-slow/shimmer` + gradients `gradient-ocean/reef/sand/wave`+`shimmer`; plugin `@tailwindcss/forms`.
- Voice/tone: verified, safety-governed, ocean-professional (teal/ocean authority, sandy neutrals, not terracotta/purple SaaS default); `frontend-design` ocean surf premium per `AGENTS.md`.
- No binding aesthetic reversal requested; future work inherits this world (use `document` to record, not replace).

## Evidence on Hand

- Repo evidence: `tailwind.config.js` (full theme), `resources/js/Pages/Welcome.vue:1-368` (hero/nav/stats/features/spots/gallery/CTA/footer + wave decoration), `resources/js/Pages/Admin/Dashboard.vue:1-305` (bento stats Bento: total bookings/instructors/revenue/incidents/live sessions + RevenueChart/BookingDistribution/PopularSpots/TopInstructors/ActivityFeed + ongoing sessions monitor), `resources/js/Components/Atoms/BaseButton.vue`, `resources/js/Components/Molecules/InstructorCard.vue`, `resources/js/Pages/Booking/*` (Create/Waiver/ParentalConsent/Payment/Confirmation), `resources/js/Layouts/GuestLayout.vue:8-26` cinematic split, `app/Enums/*.php` (SkillLevel:beginner/intermediate/advanced, InstructorLevel 1-3, BookingStatus, etc.), `resources/views/pdfs/{waiver,parental_consent,receipt}.blade.php`, `AGENTS.md`, `FEEDBACK_STRIKE_APPEAL.md`.
- Placeholder stats in `Welcome.vue:133-148` (`500+ Verified Instructors`, `12k+ Safe Sessions`, `PayMongo`) are illustrative and must be replaced with real DB counts before launch; do not treat as verified claims.
- No fabricated testimonials/benchmarks/customers added; instructor names `Junrey 'Bogs' Capistrano` / `Ana 'Maui' San Jose` in `Welcome.vue:254-287` are demonstration data and labeled synthetic until replaced with real verified profiles.

## Product Principles

1. **Verification before visibility** — no instructor surfaces in search or QR without SISA-verified status and valid certificates; trust is enforced in query scopes, not copy.
2. **Safety as code, not policy** — the 6 BR-3.* rules run in `SafetyService` before booking persistence; a blocked booking shows a human-readable `SafetyViolationException` and an audit log, never an admin bypass toggle.
3. **Student success = safe match at a fair price** — task completion (find → book → pay → surf monitored) outranks expression; pricing minimums protect both tourist and instructor wages, and the booking flow never hides eligibility errors.
4. **Live accountability** — every session is time-bound, countable, and observable (countdown, active monitor, incident→strike) so safety is not a one-time check.
5. **Siargao-native system** — walk-in QR, mobile-first beach usage, and LGU/SISA audit exports (Excel/PDF 7-year retention) are core, not edge cases.

## Accessibility & Inclusion

Mobile-first responsive (tourist on beach), WCAG 2.1 AA target via Headless UI + keyboard/focus rings in `BaseButton.vue:15`; semantic HTML, `alt` on hero/spot images, Inter/Outfit legibility, 44px touch targets, error/id states from `harden` playbook. Age gate handles minors with parental consent flow and emergency/medical data handling. No platform-specific native requirement; `web` only.
