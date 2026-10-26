# SiaSurf Application — Complete Implementation & Flow Analysis

**Analyzed:** `siasurf-app/` (Laravel 12.54.1 + Vue 3 + Inertia + MySQL + Reverb)
**Verification:** `php artisan test` → **134 passed (431 assertions), 58.5s** · All 26 migrations `Ran` · Vite build present

---

## 1. Stack Reality Check

| Planned (AGENTS.md) | Actually Implemented |
|---|---|
| Laravel 11 | **Laravel 12.54.1** |
| MySQL 8 (XAMPP) | **MySQL** (`DB_CONNECTION=mysql`, db `siasurf`) |
| Redis + Queue | `QUEUE_CONNECTION=database`, `CACHE_STORE=file`, `SESSION_DRIVER=file` — **no Redis** |
| AWS S3 | **`FILESYSTEM_DISK=local`**, S3 keys empty; certs → `public`, waivers/QR → `local` |
| Laravel Breeze + Spatie | **Both present** (`spatie/laravel-permission`, `laravel/breeze` ^2.4) |
| Reverb WebSockets | **Configured** — `config/reverb.php` exists, `BROADCAST_CONNECTION=reverb` |
| PayMongo | **Implemented behind an interface**, auto-falls back to `MockPaymentService` when keys absent |
| Tailwind | Tailwind 3 + `@tailwindcss/forms`/`typography`; uses `@heroicons/vue` + `lucide-vue-next` + `@lucide/vue` |
| PHP 8.3 | **PHP ^8.2** |

Extra integrations not in the original plan:
- **Stormglass.io tide API** (`StormglassService`) — tide warnings during booking
- **Excel exports** (maatwebsite/excel) — 4 report types
- **spatie/laravel-backup**
- **Simple QRCode** — instructor walk-in QR codes

---

## 2. Domain Model (26 migrations, 19 models)

```
User (role: student|instructor|admin)  <- HasRoles
 |- 1:1  InstructorProfile   (level 1-3, status, rate, strike_count, qr_code_path, auto_accept_bookings)
 |- 1:1  StudentProfile      (skill_level, is_first_time, status)
 |- 1:M  Certificate         (type BLS/WaSAR/SISA/ISA/SurfSkill, status, expiry)
 |- 1:M  Availability        (weekly pattern + specific_date blocks; blocked_by_booking_id)
 |- 1:M  Booking             (as student)
 |- 1:M  Booking             (as instructor)
 |- 1:M  Review              (as student / as instructor)
 \- 1:M  SafetyIncident      (as instructor / as reporter)

Booking --1:1-- Payment
Booking --1:1-- Waiver
Booking --1:1-- Review
Booking --1:1-- SafetyIncident
Booking --1:M-- BookingChangeRequest   <- reschedule/cancel audit trail
Booking --M:1-- SurfSpot
Booking --M:1-- SafetyValidationLog    <- safety audit trail
```

**21 enums** (`app/Enums/`) — all cast on models, several with `label()`, `color()` and domain logic (`InstructorLevel::maxStudents()`, `allowedSkillLevels()`).

`BookingStatus` has **11 states**, not the planned 4:
`pending -> awaiting_payment -> confirmed -> in_progress -> completed`, plus terminal `declined`, `cancelled`, `payment_timeout`, `expired`, `no_show`.

---

## 3. Module Implementation Status

### Phase 3 — Auth & Authorization ✅
- Breeze (login/register/reset/verify) + email verification.
- **Dual authorization**: Spatie roles (`role:student|instructor|admin` route middleware) **and** a denormalized `users.role` string column with `isStudent()/isInstructor()/isAdmin()` helpers + `admin`/`instructor`/`student`/`verified-instructor` Gates.
- 6 Policies: Booking, Certificate, InstructorProfile, Payment, Review, SafetyIncident.
- 3 custom middleware: `EnsureInstructorIsVerified`, `EnsureInstructorNotSuspended`, `EnsureWaiverSigned`; plus `SecurityHeaders`.
- 3 private broadcast channels authorized by role: `student.{id}`, `instructor.{id}`, `admin.notifications`.

### Phase 4 — Instructor Verification ✅
- **Two onboarding paths**: self-serve (`Instructor/OnboardingController`) and admin-created (`Admin/InstructorController@store`). Both land in `pending_verification`.
- Self-serve uploads 1..n certificates (PDF/JPG/PNG, 10MB), auto-detects `CertificateType` from filename (`sisa` tested before `isa`).
- Robust `post_max_size` detection — PHP discards oversized POST bodies entirely, so the controller detects empty `$_POST`/`$_FILES` + high `CONTENT_LENGTH` and returns an actionable message instead of a misleading "required" error.
- Admin `verify()` marks certs verified, generates the QR code, sets `active`, notifies. Blocks verification unless >=1 verified cert exists.
- `reject` -> `inactive`; `suspend`/`reactivate` (resets strikes); `toggleStatus` = "Vacation Mode".
- QR code = SVG on `local` disk encoding `route('walkin.scan', instructorId)`.

### Phase 6 — Safety Rules Engine ✅ (all 6 rules, no override)
`SafetyService::validateBookingSafety()` — pure function collecting violations, then logging to `SafetyValidationLog` **before** throwing `SafetyViolationException`:

| Rule | Implementation |
|---|---|
| L1: ≤2 students, beginners only | `validateLevel1Rules` |
| L2: 1-on-1 only | `validateLevel2Rules` |
| L3: ≤5 students | `validateLevel3Rules` |
| Age 5–12 → **Level 3** | `validateAgeRules` |
| Beginners → beginner spots only | `validateBeginnerZoneRules` |
| Cert/level must match difficulty | `validateCertificationMatchRules` |

Also: `hasTimeConflict()` (double-booking), `getBookedSlots()`, `canHandleGroupSize()`, `getApplicableRules()` (drives the UI). Rules are re-derived from `InstructorLevel` enum so they can't drift.

**3-Strike System** — `SafetyIncidentObserver::created()`: minor=1, major=2, critical=3 strikes → ≥3 strikes auto-suspends 1 month + emails admin + notifies instructor. Fired automatically via `SafetyIncident::observe()` in `AppServiceProvider`.

### Phase 5 — Booking System ✅ (two-phase, the most sophisticated part)

**Booking constants** on the model: `ACCEPT_WINDOW_HOURS=2`, `PAYMENT_HOLD_MINUTES=15`, `CHANGE_APPROVAL_THRESHOLD_HOURS=24`, `PRE_LESSON_BUFFER_HOURS=1`, `START_TOLERANCE_HOURS=2`, `MAX_OPEN_REQUESTS_PER_STUDENT=3`.

Flow (`BookingController`):
1. `create` — renders form + applicable safety rules + surf spots + tide data
2. `storeDetails` — validates (incl. height/weight for board sizing) → computes `end_time`/`duration_hours=1` → derives `time_period` from hour → **tide warning** → **6 safety rules** → **time conflict** → **instructor availability** → **pricing** → **abuse guard** → creates `Pending`
3. `waiver` / `parental-consent` (auto-triggered when age < 18)
4. `storePayment` — creates Payment, sets `accept_deadline_at`, broadcasts `BookingCreated`, dispatches `ExpireBookingAcceptanceJob`
5. Instructor accepts/declines → `BookingAcceptanceService`
6. `confirmation`

**Three consolidated services prevent logic drift** (explicitly noted in code as replacing duplicated logic):
- `AvailabilityService` — sole authority for slot locking/releasing (`blockSlot`, `releaseSlot`, `blockForBooking`, `releaseForBooking`, `moveBookingSlot`, `bookabilityReason`, `bookableStartTimes`, `weeklyPattern`). Distinguishes **manual blocks** from **booking-owned locks**; never deletes manual blocks; only releases if no other live booking holds the slot.
- `BookingAcceptanceService` — sole authority for accepting. Branches: **GCash** → `awaiting_payment` + payment intent + `ExpirePaymentWindowJob`; **Cash** → immediately `confirmed`. Defaults to Cash (conservative) when no payment record exists.
- `BookingChangeService` — FR 1.9.4. Cancellation/reschedule. Under 24h → parked for admin approval (`requires_admin_approval`); further out → auto-applied + audited. Validates new slot against conflicts and manual blocks.

**Instant Book** — instructors with `auto_accept_bookings=true` skip the 2-hour queue entirely (`finalizeInstantBook`).

**`BookingRequestGuard`** — anti-abuse: blocks duplicate slot requests, caps 3 open requests per student.

**Availability resolution** — `periodsFor()` returns per-period `{available, reason, slots}`; `bookableStartTimes()` generates hourly slots excluding overlaps and anything within a 1-hour buffer. The Vue `Create.vue` fetches this via `/api/availability/{instructor}` on every date change, so students only see genuinely bookable times.


### Phase 7 — Pricing & Payments ✅
`PricingService`: base rates L1=₱600, L2=₱800, L3=₱1500/hr; `STANDARD_HOURS=1`; add-ons video ₱500 / photography ₱800; weekly −10%, monthly −20%. Validates group size against `InstructorLevel::maxStudents()`. Rejects rates below minimum (`rate_per_hour` `min:600` everywhere).

`PaymentServiceInterface` with two impls, bound in `AppServiceProvider::register()` — empty `services.paymongo.secret_key` ⇒ `MockPaymentService`. PayMongo creates a payment intent + GCash checkout session, handles webhooks, refunds, and status polling. Mock version stores in-memory intents and exposes a dev checkout at `/mock-checkout/{transactionId}` (guarded to `local`/`development`/`testing`).

**Two timed safety nets** (queued jobs):
- `ExpireBookingAcceptanceJob` — instructor silent >2h → `expireAcceptance()`, release slot, fail pending payment, broadcast `BookingDeclined(expired:true)`, notify both parties.
- `ExpirePaymentWindowJob` — student silent >15min → `markPaymentTimedOut()`, release slot, broadcast `BookingCancelled`, notify both parties.

Both re-check the deadline on run to avoid racing a late action.

### Phase 8 — Waivers ✅
`WaiverService` — liability always required; parental consent when `student_age < 18`; DomPDF A4 PDFs (`pdfs/waiver.blade.php`, `pdfs/parental_consent.blade.php`); `retention_until` = +7 years; `cleanupExpiredWaivers()`. `EnsureWaiverSigned` middleware redirects to the missing step.

### Phase 9 — Reviews, Sessions & Incidents ✅
- Written review (no numeric rating — `rating` was made nullable in a later migration), photo upload, one per booking, instructor can respond once, admin moderation, stats + distribution + response rate.
- **Session tracking**: `startSession()` guarded by `startSessionBlockReason()` (must be `confirmed`, must be today, within ±2h of start) — returns a human-readable reason the UI turns into the correct next action. `getSessionTimeRemaining()` countdown. `markAttendance()` — absent on a live session auto-completes it.
- **Board recommendation** — `getRecommendedBoardSize()` derives volume from weight × skill factor, length range from height, soft-top vs hard-top.

### Phase 10 — Admin Dashboard ✅
`DashboardService` — overview stats with trend %, revenue (total/month + 12-month series), booking trends (30d), popular spots, top instructors, activity feed, safety stats, booking distribution, instructor stats. Served through 8 `/admin/api/dashboard/*` endpoints. Vue organisms: `RevenueChart`, `PopularSpotsChart`, `BookingDistributionChart`, `TopInstructorsTable`, `ActivityFeed`, `StatCard`.
4 Excel exports: instructor performance, revenue, session logs, safety incidents.

### Phase 11 — Real-Time ✅
11 `ShouldBroadcast` events. 3 authorized private channels. `useNotifications.ts` keeps a **single module-scope Echo instance** (a per-component one would duplicate events), subscribes per role, caps the feed at 50, and only disconnects when nothing else is listening. Authorizer posts to `/broadcasting/auth` with the session cookie/CSRF. Also has DB notifications (`create_notifications_table`) + 10 `Notification` classes.

### Scheduling (`routes/console.php`)
- `SendReviewRequestEmails` — hourly, `withoutOverlapping`
- `FetchTideDataCommand` — daily 00:05 (1 of 10 free Stormglass calls/day)
- `ExpireStaleBookingRequests` — every 5 minutes (safety net if queue jobs are lost)



---

## 4. End-to-End Flows

### A. Instructor onboarding → first booking
```
Register (role=instructor) -> dashboard bounce
  -> no profile/no cert?  -> Onboarding (bio, level, rate, >=1 cert)
  -> status pending?      -> Instructor/Pending
  -> status suspended?    -> Instructor/Suspended
  -> verified             -> Instructor/Dashboard
Admin reviews certs -> verify -> QR generated -> status=active -> InstructorVerified notification
Instructor sets weekly availability + optional Instant Book + Vacation Mode
```

### B. Student booking (standard, non-Instant-Book)
```
Search (filters: skill, level, price, full-text bio; only Active + non-suspended)
  -> Profile (weekly availability shown up front)
  -> bookings.create            [safety rules + spots + tides returned]
  -> storeDetails               [6 safety rules -> conflict -> availability -> pricing -> guard]
  -> bookings.waiver            [liability; parental consent auto-required if <18]
  -> storePayment               [status=Pending, accept_deadline=+2h, broadcast, queue expiry job]
  -> INSTRUCTOR ACCEPTS         [slot locked -> GCash: awaiting_payment +15min | Cash: confirmed]
  -> payment completes          -> confirmed
  -> instructor starts session  [same-day, +/-2h tolerance] -> in_progress + countdown
  -> attendance marked -> completed
  -> review request email (hourly job) -> written review -> instructor may respond
```

### C. Walk-in (QR)
```
Instructor QR (SVG, local disk) encodes route('walkin.scan', id)
  -> tourist scans -> /qr/{instructor} -> WalkIn/Scan (glassmorphic card, verified badge)
  -> "Book This Session" -> standard booking flow with instructor pre-filled
```
`StudentProfile::walkin_token` + `QRCodeService::generateStudentQR()` exist for student-side check-in QR, but no route consumes it yet.

### D. Late change (FR 1.9.4)
```
Instructor files cancel/reschedule via BookingChangeService
  |- >24h out  -> auto-apply (release old slot, lock new), record audit trail
  \- <24h      -> requires_admin_approval -> admin.change-requests queue -> approve/reject
                   (reject keeps the original slot)
```

### E. Safety incident
```
Admin logs incident (type, severity, people, description, location)
  -> SafetyIncidentObserver::created -> strikes by severity
  -> >=3 strikes -> suspend 1 month + hide from search + email admin + notify instructor
```

---

## 5. Architecture Quality

**Strengths**
- **Service layer genuinely enforced.** `AvailabilityService` / `BookingAcceptanceService` / `BookingChangeService` are single sources of truth; comments explicitly state they exist to stop three-way logic drift.
- **Safety rules are centralized and non-overridable**, logged before throwing, with no admin bypass. Derived from enums.
- **Extensive "why" documentation.** Comments explain the failure mode being prevented (slot-release conditions, deadline clamping so a 2h window can't land after the lesson, 1-hour pre-lesson buffer, conservative Cash fallback).
- **Idempotent, race-aware expiry jobs** that re-verify the deadline and current status.
- Every state transition releases or moves the slot lock; cancellations, expiry, reschedule all route through `AvailabilityService`.
- Boundary-condition handling is unusually thorough: `post_max_size` body discard, PHP ini->bytes conversion shared with `HandleInertiaRequests` to keep the UI honest about upload limits.

**Gaps / risks**
- **Payments are not wired to the booking flow.** `BookingAcceptanceService::awaitPayment()` calls `createPaymentIntent()`, but `PaymentController::show()` renders `Payment/Show.vue`, a page that does not exist (only `Booking/Payment.vue` and `Payment/MockCheckout.vue` do). The GCash return path is incomplete.
- **`PaymentReceived` / `InstructorStatusChanged` events exist but are never broadcast.** `processSuccessfulPayment()` still carries `// TODO: Broadcast payment received event (Phase 11)` in both payment services. Frontend listeners for `.payment.received` / `.instructor.status.changed` therefore never fire.
- **Instructor suspension is not self-healing.** `suspended_until` is written on auto-suspend but nothing reactivates an instructor when it elapses (no scheduled command).
- **`config/broadcasting.php` is absent**, so Reverb config is not published/validated there.
- **No E2E tests.** 134 feature/unit tests, no Playwright/Dusk despite `webapp-testing` being the planned Phase 12 skill.
- **Rate limiting** is only on Breeze defaults (`throttle:6,1` on email verification); no custom limits on login/payments/API as the security checklist requires.
- **Virus scanning** is stubbed (`VIRUS_SCAN_ENABLED=false`, no consumer).
- **File storage** is `local`/`public` with no signed URLs, and the 7-year legal retention is enforced only by an unscheduled `cleanupExpiredWaivers()`.
- **Frontend dead weight**: duplicate icon packages (`@heroicons/vue`, `lucide-vue-next`, `@lucide/vue`), duplicate layouts (`AppLayout`, `AuthenticatedLayout`, `AppSidebarLayout`), and `Pages/Dashboard.vue` alongside the three role dashboards.
- **`fullText('bio', …)`** search requires a MySQL FULLTEXT index on `instructor_profiles.bio`; the migration set does not appear to create one — this may fail on some MySQL configurations.
- **Repo hygiene**: ~12 test-output `.txt` files; `.env` and `.env.backup` with a **live Stormglass API key and Reverb secret** tracked in the working tree; `.git` sits next to a disabled `.git_disabled`.
- `PricingService` hardcodes `STANDARD_HOURS = 1`, so the 2-hour package flexibility the PRD implies is unused — `BookingController` also always sends 1 hour.

---

## 6. Verification Evidence

```
php artisan --version          -> Laravel Framework 12.54.1
php artisan route:list         -> 125 route entries (non-vendor)
php artisan migrate:status     -> all migrations Ran (batches 1-3)
php artisan test               -> Tests: 134 passed (431 assertions), 58.52s
public/build/manifest.json     -> present, built 9/18/2026 4:36 PM
```

Test coverage by area: Breeze auth (6 files), instructor verification/QR/suspend (9 tests), booking flow + safety + expiry jobs (15), instructor booking workflow incl. accept/decline/reschedule/attendance (~25), onboarding (12), availability, profile, `SafetyServiceTest`, `WaiverServiceTest`.

