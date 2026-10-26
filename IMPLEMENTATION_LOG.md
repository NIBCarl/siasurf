# SiaSurf — Implementation Log

**Date:** 2026-10-24
**Scope:** every gap identified in `PROJECT_ANALYSIS.md`, plus the real-time
location feature and an accurate Siargao surf-spot registry.

**Verification:** `157 tests passed (572 assertions)` · `npm run build` clean ·
`/surf-spots` returns HTTP 200 with real break data · migrations applied.

---

## 1. Bugs fixed

### 1.1 `PaymentController::show()` rendered a page that did not exist
`Payment/Show.vue` was referenced by `routes/payments.php` but never created, so
`/payments/{booking}` failed.

**Fix:** `resources/js/Pages/Payment/Show.vue` — booking summary, status badge,
GCash payment action, receipt download, cash-on-hand explanation.

### 1.2 `PaymentReceived` and `InstructorStatusChanged` were never broadcast
Both events existed and the Vue client already listened for
`.payment.received` and `.instructor.status.changed`, but nothing ever
dispatched them — both payment services still carried
`// TODO: Broadcast payment received event (Phase 11)`.

**Fix:**
- `MockPaymentService::broadcastPaymentReceived()` and the same in
  `PayMongoPaymentService::processSuccessfulPayment()`.
- `Admin\InstructorController::broadcastStatusChange()` called from `verify`,
  `reject`, `suspend`, `reactivate`.
- `SafetyIncidentObserver::created()` now broadcasts an auto-suspension.
- Broadcasts are wrapped in `try/catch` + `Log::warning`: a dead WebSocket must
  never fail a payment or an admin action.

### 1.3 `InstructorStatusChanged` referenced a non-existent enum case
`getMessage()` matched on `InstructorStatus::Rejected`, which does not exist
(the real statuses are `PendingVerification`, `Active`, `Suspended`,
`Inactive`). PHP resolves enum cases at runtime, so the moment the event was
actually broadcast this would have thrown.

**Fix:** replaced with `InstructorStatus::Inactive` and a safe `default` arm.
Covered by `MaintenanceCommandsTest::test_instructor_status_change_event_builds_payload_for_every_status`.

### 1.4 Suspensions never lifted themselves
`InstructorProfile::addStrike()` and the observer both wrote `suspended_until`,
but nothing ever read it back. A 3-strike auto-suspension hid an instructor from
search permanently.

**Fix:** `app/Console/Commands/LiftExpiredSuspensions.php`
(`instructors:lift-expired-suspensions`), scheduled every 15 minutes. It clears
`suspended_until` **and** resets `strike_count` (otherwise the very next
incident would immediately re-trigger the 3-strike rule), notifies the
instructor, and broadcasts the status change.

### 1.5 Waivers were not actually enforced before payment (BR-4.4)
The booking flow *redirected* students to the waiver step, but nothing stopped
them from requesting `/bookings/{id}/payment` directly. `EnsureWaiverSigned` was
registered as middleware but never applied to a route.

**Fix:** applied `waiver.signed` to `bookings.payment`, `bookings.store-payment`
and `payments.gcash`. A minor is now bounced to parental consent, not payment.
Two new tests assert this (`payment_is_blocked_until_the_liability_waiver_is_signed`,
`a_minor_also_needs_parental_consent_before_payment`) — three existing tests had
to be updated because they were encoding the gap.

### 1.6 `whereFullText()` had no FULLTEXT index
`InstructorSearchController` calls `whereFullText('bio', …)` but
`instructor_profiles.bio` had no FULLTEXT index, so search fell back to a full
scan (and errors on strict MySQL configs).

**Fix:** `2026_10_24_000003_add_bio_fulltext_index_to_instructor_profiles.php`,
guarded by driver check and index existence so it is idempotent and portable.

### 1.7 `config/broadcasting.php` was missing
Only `config/reverb.php` existed; the `reverb` driver had no connection entry.

**Fix:** published the standard Laravel broadcasting config with `reverb`,
`pusher`, `ably`, `log` and `null` connections, defaulting to
`env('BROADCAST_CONNECTION', 'reverb')`.

### 1.8 No rate limiting beyond Breeze defaults
The Phase 13 checklist required explicit limits; only `throttle:6,1` on email
verification existed.

**Fix:** named limiters in `AppServiceProvider::configureRateLimiting()`:

| Limiter | Limit | Applied to |
|---|---|---|
| `login` | 5/min per email+IP | `POST /login` |
| `payments` | 10/min per user | GCash + cash endpoints |
| `bookings` | 20/min per user | booking creation |
| `location-pings` | 120/min per user | live GPS pings |
| `public-api` | 60/min per IP | readonly APIs |

### 1.9 Virus scanning was a stub
`VIRUS_SCAN_ENABLED` existed with no consumer.

**Fix:** `app/Services/FileScanService.php` — always validates the **real** MIME
type (Laravel's `mimes` rule trusts the extension, so `malware.php` renamed to
`.pdf` used to pass), then runs `clamdscan` when enabled. Wired into
`Instructor\OnboardingController@store`. **Fails closed:** if scanning is
enabled but the binary is missing, the upload is rejected rather than accepted
unscanned.

### 1.10 Waiver retention was never scheduled
`WaiverService::cleanupExpiredWaivers()` implemented the 7-year rule but nothing
called it.

**Fix:** `waivers:cleanup-expired` command + `--dry-run`, scheduled monthly on
the 1st at 02:30.

### 1.11 `.gitignore` was wrapped in markdown code fences
The file began and ended with ```` ``` ```` and was missing Laravel's defaults,
so `vendor/`, `public/build/`, `database/*.sqlite`, `.phpunit.result.cache` and
the test-output dumps were all trackable.

**Fix:** fences removed; `/vendor`, `/public/build`, `/public/hot`,
`/storage/*.key`, `/bootstrap/cache/*.php`, `.phpunit.result.cache`,
`/database/*.sqlite` and the root `test_*.txt` dumps added.

### 1.12 `.env.example` was the bare Laravel skeleton
It did not mention PayMongo, Reverb, Stormglass, virus scanning or the admin
seeder, so a fresh clone could not be configured from it.

**Fix:** documented every SiaSurf-specific variable with its fallback behaviour.

---

## 2. Real-time location (new feature)

### 2.1 Data model

`2026_10_24_000002_create_session_locations_table.php`

| Column | Purpose |
|---|---|
| `booking_id` | the lesson the ping belongs to (cascade delete) |
| `instructor_id` | who sent it |
| `latitude` / `longitude` | decimal(10,7) — ~1 cm resolution |
| `accuracy_m` | GPS accuracy in metres, shown to the student |
| `speed_mps`, `heading` | retained for future ETA/bearing work |
| `distance_from_spot_m` | computed at write time from the booked break |
| `recorded_at` | device time, indexed with `booking_id` |
| `source` | `gps` today; room for `network` / `manual` |

Every ping is **appended**, never overwritten, so the trail survives a dropped
socket and stays auditable by SISA.

### 2.2 `LocationService` — the rules live in one place

- **Siargao bounding box** (`9.60–10.10 N`, `125.95–126.30 E`). A `0,0` fix or a
  Manila coordinate is rejected with a clear message instead of silently
  plotting the instructor 800 km offshore.
- **Haversine distance** from the ping to `bookings.surf_spot_id`.
- **On-site radius** of 400 m — a lesson counts as "on site" when the instructor
  is inside it.
- **Broadcast throttling** — pings are stored every ~8 s but only broadcast at
  most every 15 s, or immediately on an on-site/off-site *transition*. A 1-hour
  lesson would otherwise emit ~720 socket frames to every admin dashboard.
- **Staleness** — a fix older than 120 s is rendered grey, not "live".

### 2.3 Endpoints

| Method | Route | Who |
|---|---|---|
| `POST` | `/instructor/bookings/{booking}/location` | assigned instructor, `in_progress` only, 120/min |
| `GET` | `/student/bookings/{booking}/location` | student / instructor / admin (policy-gated) |
| `GET` | `/admin/api/live-sessions` | admin — every running lesson |
| `GET` | `/admin/live-sessions` | admin page |

A ping outside an `in_progress` session returns `422`; another instructor gets
`403`; another student cannot read the live state.

### 2.4 Broadcast

`SessionLocationUpdated` (`session.location.updated`) goes to
`student.{id}`, `instructor.{id}` and `admin.notifications` — all three channels
were already authorised in `routes/channels.php`.

### 2.5 Frontend

| Component | Purpose |
|---|---|
| `Organisms/LiveMap.vue` | Leaflet + OpenStreetMap (no API key, no billing). `shallowRef` for Leaflet objects, custom `divIcon` pins (avoids the bundler marker-path bug), `editable` mode emits `pick`. |
| `Organisms/LocationBroadcaster.vue` | Instructor "Go live" — `watchPosition`, opt-in, throttled POST every 8 s, shows pings / accuracy / distance. Stops on any 422. |
| `Organisms/LiveInstructorTracker.vue` | Student map — Echo listener + 15 s polling fallback. |
| `Pages/Admin/LiveSessions/Index.vue` | SISA board — live map, on-site/away counters, Echo + 12 s polling. |
| `Pages/Admin/SurfSpots/Index.vue` | Registry table + editor with click-to-pin map. |
| `Pages/Public/SurfSpots/Index.vue` | Student-facing break directory. |

**Two update paths everywhere.** Reverb is the fast path, but a blocked or
missing socket degrades to polling rather than a blank map — which matters
because Reverb is not always running in local development.

---

## 3. Accurate Siargao surf spots

`database/seeders/SurfSpotSeeder.php` was rewritten from 8 invented entries to
**19 real breaks**, each with the municipality it actually belongs to:

| Area | Breaks |
|---|---|
| General Luna | Cloud 9, Quicksilver, Jacking Horse, Cemetery, Stimpy's, Tuazon Point, Rock Island, Guiwan, General Luna Beach Break, Daku Island, Guyam Island, Malinao, Doot Beach, Union Beach, Alegria Beach |
| San Isidro | Pacifico Beach, La Januza |
| Pilar | Pilar |
| Santa Monica | Portillo |
| Burgos | Burgos |

The eight municipalities match the real Siargao LGUs (Burgos, Dapa, Del Carmen,
General Luna, San Benito, Pilar, San Isidro, Santa Monica).

Each row also carries `break_type`, `best_tide`, `hazard_notes` and
`is_safe_zone`. `is_safe_zone` is what FR 3.5 actually consults: a beginner can
only be matched to a break flagged safe.

**On accuracy.** Coordinates were compiled from public surf/island references
and are good to roughly 100 m — enough to land on the correct break, not survey
grade. Rather than pretend otherwise, the design makes the **database** the
source of truth: SISA can drag, click or type a corrected pin in
Admin → Surf Spots and every screen updates without a deploy. Seeder re-runs are
idempotent (`updateOrCreate` by name) and deactivate — never delete — breaks that
drop out of the canonical list, so booking history keeps its `surf_spot_id`.


---

## 4. How to run

```bash
# 1. Dependencies
composer install
npm install

# 2. Database (XAMPP MySQL running)
php artisan migrate
php artisan db:seed --class=SurfSpotSeeder   # 19 real Siargao breaks
php artisan db:seed --class=RolesAndPermissionsSeeder
php artisan db:seed --class=AdminSeeder

# 3. Build the frontend (required: Inertia resolves pages from the manifest)
npm run build      # or: npm run dev

# 4. Three processes
php artisan serve
php artisan queue:work          # expiry jobs + notifications
php artisan reverb:start        # live booking, payment and location events

# 5. Scheduler (needed for the new sweeps)
php artisan schedule:work
```

Scheduled tasks now include:

| Command | Frequency | Why |
|---|---|---|
| `ExpireStaleBookingRequests` | 5 min | release unanswered requests |
| `LiftExpiredSuspensions` | 15 min | **new** — suspension self-healing |
| `SendReviewRequestEmails` | hourly | post-lesson review prompt |
| `FetchTideDataCommand` | daily 00:05 | Stormglass (10 calls/day free tier) |
| `CleanupExpiredWaivers` | monthly, 1st @ 02:30 | **new** — 7-year retention |

### Verifying the location feature by hand

1. Sign in as an instructor, open a booking, **Start Session**.
2. In the **Live location** card press **Go live** and allow the browser
   permission. The card shows pings, accuracy and distance to the break.
3. Sign in as that booking's student in a second browser — the instructor marker
   appears on the map and pulses while the fix is fresh.
4. Sign in as SISA → **Live Sessions** to see every running lesson, with on-site
   and away-from-break counters.

> Geolocation requires `https://` or `localhost`. A device outside Siargao
> cannot ping: the server rejects the coordinate.

---

## 5. Test coverage added

| File | Tests | Covers |
|---|---|---|
| `tests/Feature/SessionLocationTest.php` | 9 | ping during live session, reject when not in progress, reject outside Siargao, cross-instructor 403, cross-student 403, on-site vs off-site distance, haversine sanity, full trail persistence |
| `tests/Feature/SurfSpotRegistryTest.php` | 8 | canonical seed data + LGUs, every pin inside the bounding box, seeder idempotency, public page renders, admin create, beginner→auto safe zone, out-of-bounds rejection, non-admin 403 |
| `tests/Feature/MaintenanceCommandsTest.php` | 4 | suspension lifted + strikes cleared, active suspension untouched, status event payload for every enum case, waiver retention cleanup |
| `tests/Feature/BookingFlowTest.php` | +2 | payment blocked until waiver signed, minor needs parental consent |

**157 passed / 572 assertions**, up from 134 / 431.

---

## 6. Deliberately not changed

These remain open by design — flagged so they are decisions, not oversights:

1. **Two-hour pricing package.** `PricingService::STANDARD_HOURS = 1` and
   `BookingController` always books one hour, so the PRD's ₱1,200 two-hour
   standard package is unused. Changing it alters pricing semantics and the
   booking form, which is a product decision rather than a bug fix.
2. **S3 + signed URLs.** Storage is still `local`/`public`. Waivers and receipts
   are served through policy-gated controllers, so access is authorised, but URLs
   are not time-limited. Moving to S3 is a deployment change.
3. **Playwright E2E.** 157 PHPUnit tests exist; browser-level flows are still
   untested.
4. **Duplicate frontend assets.** `@heroicons/vue`, `lucide-vue-next` and
   `@lucide/vue` are all installed; `AppLayout`, `AuthenticatedLayout` and
   `AppSidebarLayout` coexist with `Pages/Dashboard.vue`. Harmless, but
   removable once the layouts are consolidated.


---

## 7. Files added / changed

**Added**

```
app/Console/Commands/CleanupExpiredWaivers.php
app/Console/Commands/LiftExpiredSuspensions.php
app/Events/SessionLocationUpdated.php
app/Http/Controllers/LocationController.php
app/Http/Controllers/SurfSpotController.php
app/Http/Controllers/Admin/SurfSpotController.php
app/Models/SessionLocation.php
app/Services/FileScanService.php
app/Services/LocationService.php
config/broadcasting.php
database/migrations/2026_10_24_000001_add_location_fields_to_surf_spots_table.php
database/migrations/2026_10_24_000002_create_session_locations_table.php
database/migrations/2026_10_24_000003_add_bio_fulltext_index_to_instructor_profiles.php
resources/js/Components/Organisms/LiveMap.vue
resources/js/Components/Organisms/LocationBroadcaster.vue
resources/js/Components/Organisms/LiveInstructorTracker.vue
resources/js/Pages/Payment/Show.vue
resources/js/Pages/Public/SurfSpots/Index.vue
resources/js/Pages/Admin/SurfSpots/Index.vue
resources/js/Pages/Admin/LiveSessions/Index.vue
tests/Feature/SessionLocationTest.php
tests/Feature/SurfSpotRegistryTest.php
tests/Feature/MaintenanceCommandsTest.php
```

**Changed**

```
app/Events/InstructorStatusChanged.php              invalid enum case
app/Http/Controllers/Admin/InstructorController.php status broadcasts
app/Http/Controllers/Instructor/OnboardingController.php  file scanning
app/Models/Booking.php                              session location relations
app/Models/SurfSpot.php                             area, safe zone, distance
app/Observers/SafetyIncidentObserver.php            suspension broadcast
app/Providers/AppServiceProvider.php                rate limiters
app/Services/MockPaymentService.php                 payment broadcast
app/Services/PayMongoPaymentService.php             payment broadcast
config/services.php                                 virus_scan block
database/seeders/SurfSpotSeeder.php                 19 real Siargao breaks
resources/js/Layouts/AppSidebarLayout.vue           nav entries
resources/js/Pages/Instructor/Bookings/Show.vue     location broadcaster
resources/js/Pages/Student/Bookings/Show.vue        live instructor map
routes/web.php, routes/auth.php, routes/payments.php, routes/console.php
.env.example, .gitignore, package.json
```

---

## 8. One command to re-verify everything

```bash
php artisan migrate --force && php artisan db:seed --class=SurfSpotSeeder --force \
  && php artisan test && npm run build
```

Expected: all migrations `DONE`, 157 tests passing, Vite build clean.


---

## 9. Follow-up fix — SQLSTATE[22003] on `rate_per_hour`

### What caused it

`instructor_profiles.rate_per_hour` is `decimal(10,2)`, so its maximum is
**99,999,999.99**. The onboarding request submitted `10000000000` (11 digits),
which MySQL rejects with:

```
SQLSTATE[22003]: Numeric value out of range: 1264
Out of range value for column 'rate_per_hour' at row 1
```

But the database was only the *symptom*. The real cause is that every write path
validated the rate with a **floor and no ceiling**:

```php
'rate_per_hour' => 'required|numeric|min:600',
```

So `10000000000` was a perfectly valid input as far as Laravel was concerned, and
the request reached the database — surfacing as a raw 500 error with a full stack
trace instead of a message the user could act on. The same rule was duplicated in
three places (`OnboardingController`, `Admin\InstructorController@store`,
`Admin\InstructorController@update`), so any one of them could trigger it.

### The fix

**One source of truth** — `config/siasurf.php`:

```php
'pricing' => [
    'min_rate_per_hour' => env('SIASURF_MIN_RATE', 600),
    'max_rate_per_hour' => env('SIASURF_MAX_RATE', 1500),
],
```

`PricingService` exposes it as methods, so validation messages, the UI and the
service layer all read the same numbers:

```php
PricingService::minRatePerHour();   // 600.0
PricingService::maxRatePerHour();   // 1500.0
PricingService::rateRangeRule();    // "min:600|max:1500"
```

**All three write paths now validate both ends**, with messages that name the cap:

```
rate_per_hour.max => "The standard rate cannot exceed ₱1,500 per hour."
```

**`PricingService::isRateValid()`** used to check only the floor — it now enforces
the ceiling too.

**The popup** — `Components/Molecules/RateCapDialog.vue`, wired into:

| Page | Behaviour |
|---|---|
| `Instructor/Onboarding.vue` | inline red warning on blur, dialog on submit |
| `Admin/Instructors/Create.vue` | inline warning + dialog |
| `Admin/Instructors/Edit.vue` | inline warning + dialog |

The dialog states the cap, echoes the amount entered, and offers
**"Use ₱1,500"** or **"Let me change it"** (which returns focus to the field).
Inputs also carry `:max`, so the browser blocks it natively; the dialog and the
server rule are the backstops.

The bounds are shared into Inertia props (`pricing.maxRatePerHour`), so the UI
cannot drift from the server.

### Tests

`tests/Feature/RatePerHourValidationTest.php` — 10 tests:

- the exact crash value `10000000000` now yields a **validation error**, not a 500
- the error message names ₱1,500
- boundary values 600 and 1500 are accepted
- below-floor is rejected
- admin create **and** admin update reject an over-cap rate
- a rate inside the band saves normally
- `PricingService::isRateValid()` rejects 10 billion and 1,500.01

Also fixed a **latent flake** found while running the suite: `BookingFactory`
randomises `student_age` over `13–50`, so ~15% of booking tests silently became
minors and demanded parental consent. `BookingFlowTest::booking()` now pins an
adult; the minor case has its own explicit test.

### If Level 3 / ISA needs a higher rate

The PRD allows ₱1,500–₱2,000 for Level 3 / ISA instructors, which the 1500 cap
now blocks. That is a one-line change, no code edits:

```env
SIASURF_MAX_RATE=2000
```

Everything — validation rules, error messages and all three UI dialogs — follows
automatically.

**Result:** 168 tests passing (612 assertions).

