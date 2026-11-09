# Handover Prompt - SiaSurf Capacity + Checkout Hold Implementation

Copy the text below into a new session.

---

You are continuing work on **SiaSurf**, a Laravel 12 + Vue 3 + Inertia + TypeScript booking platform for surf lessons in Siargao.

**Workspace:** `c:\Users\Engr. John Rome\Documents\personal\SIASURF\siasurf-app`
**Stack:** Laravel 12.54.1, PHP 8.4, MySQL 8 (Laragon), Vue 3 + Inertia + TypeScript, Tailwind, Spatie Permission, Laravel Reverb, PayMongo (GCash) + cash.

## Read these first (in this order)

1. `AGENTS.md` - project-wide rules and safety constraints
2. `BOOKING_FLOWS.md` - the current implemented flow
3. `SPOT_BASED_BOOKING_PROPOSAL.md` - the approved spot-pin and tide flow (V2)
4. `BOOKING_CHECKOUT_HOLD_SPEC.md` - the approved decisions you must implement (the primary contract)

Do not re-litigate the decisions in those documents. Implement them. If something genuinely conflicts, stop and report the conflict instead of guessing.

## The task

Implement the approved booking capacity and checkout-hold changes.

### 1. Correct the capacity table

| Level | Seats | Group |
|---|---|---|
| Level 1 | **1** | 1-on-1 only |
| Level 2 | **2** | 1 or 2 |
| Level 3 | **5** | 1 to 5 |

Capacity is **per instructor**, never per surf spot. A spot has **no seat cap** and **no instructor cap**. Level 1 becomes exclusive naturally because its capacity is 1; no separate exclusive flag is needed.

### 2. Add a checkout hold between details and payment method

- The student's selected seats are reserved in the database immediately at details submission.
- The spot is pinned at details submission.
- The instructor is **not notified** and the booking is **not visible in the instructor roster** during checkout.
- The fallback ceiling scales with group size:
  `min(30, 15 + 5 x (participants - 1))` minutes, configurable.
- While the waiver page is open, a heartbeat extends the hold. There is **no automatic release while the student is actively signing**.
- Release the seats immediately when the student confirms cancel / "do not proceed", or navigates away / closes the tab (best effort via `pagehide` + `sendBeacon`).
- If the browser disappears without a signal (crash, network loss), the fallback ceiling expires the hold.
- When the student chooses a payment method, convert the checkout booking to `pending`, set the 2-hour acceptance deadline, dispatch the acceptance job, and notify the instructor for the first time.

### 3. Preserve skill-level matching exactly

- Beginner sees L1/L2/L3 instructors.
- Intermediate sees L2/L3 only.
- Advanced/expert sees L3 only.
- Spot difficulty: beginner -> beginner; intermediate -> beginner/intermediate; advanced -> all.
- Groups use `SkillLevel::mostRestrictive()`.
- `SafetyService` remains the final gate.

### 4. Keep group booking supported

- L1 max 1 (no group), L2 max 2, L3 max 5.
- The student fills in the group and participant details (age, height/weight unless own board, skill, emergency/medical info, guardian info for minors).
- Every participant signs their own waiver; minors also require parental consent.
- If the group changes during checkout, re-check capacity, price, the most restrictive skill against the pinned spot, and extend the hold using the new group size.

## Current code state vs target

The code still encodes the **old** table (L1 = 2, L2 = 1, L3 = 5) and has **no** checkout hold. The docs have been updated to the target. Bring the code, tests and UI in line.

Behaviours to change:

- `BookingController::storeDetails` creates the booking before the waiver but sets `accept_deadline_at` there and does **not** dispatch the acceptance job until `storePayment`.
- `BookingStatus` has 11 states and no `checkout`.
- `InstructorLevel::maxStudents()` returns 2/1/5.
- `SafetyService::validateLevel1Rules` allows 2 students; `validateLevel2Rules` enforces 1-on-1.
- `config/siasurf.php` defaults 2/1/5.
- `AvailabilityService::remainingCapacity()` sums `student_count` per instructor/time; no spot filter.
- `holdsSlotWhilePending()` makes L1 exclusive; with L1 capacity 1 this is redundant.
- `storePayment` sets the acceptance deadline, dispatches `ExpireBookingAcceptanceJob`, broadcasts `BookingCreated`.
- `BookingObserver` provisions chat only from `confirmed` (keep this).
- `Instructor\BookingController::index` and notifications currently see `pending` bookings.
- `Booking/Create.vue` computes `maxStudents` as 2/1/5.
- `Instructor/Dashboard.vue` labels Level 2 as "one-on-one".
- Tests still expect the old capacity table.

## Implementation outline

### Phase 1 - Capacity table

Update:

- `app/Enums/InstructorLevel.php`: `maxStudents()` 1/2/5, `description()`, `allowsParallelBookings()` (L2 true), simplify/remove `holdsSlotWhilePending()`.
- `app/Services/SafetyService.php`: L1 max 1, L2 max 2, `validateParticipants`, `getApplicableRules`.
- `config/siasurf.php`: `instructor_capacity` defaults 1/2/5.
- `resources/js/Pages/Booking/Create.vue`: `maxStudents` 1/2/5; disable group for L1; helper text.
- `resources/js/Pages/Instructor/Dashboard.vue`: Level 2 label.
- `database/factories/BookingFactory.php`: random counts respect 1/2/5.
- Tests: `SafetyServiceTest`, `SkillLevelMatchingTest`, `SlotCapacityTest`, `BookingControllerTest`, `BookingFlowTest`, `InstructorDirectoryTest`.
- Stale rules in root docs: `AGENTS.md`, `prd.md`, `masterplandevelopment.md`, `siargao-surfinfo`, `IMPLEMENTATION_CORRECTIONS.md`.

### Phase 2 - Checkout status and hold

- Add `BookingStatus::Checkout = 'checkout'` (12 states), include it in `slotHolding()`; allow release/cancel from it.
- Migration: `checkout_hold_until`, `checkout_heartbeat_at`, `checkout_released_at`, `checkout_release_reason`, plus the spot-pin/audit fields from the proposal (`spot_pin_id`, `spot_confirmed_at`, `spot_confirmed_by`, `tide_snapshot`) as needed.
- `BookingController::storeDetails`:
  - Create the booking as `checkout`.
  - Hold the seats and pin the spot at details submission.
  - Do not set `accept_deadline_at`.
  - Do not dispatch `ExpireBookingAcceptanceJob`.
  - Do not broadcast/notify the instructor.
  - Set `checkout_hold_until` from the formula.
- New `CheckoutController` (or BookingController methods):
  - `POST /bookings/{booking}/checkout/heartbeat` - extend the hold; owning student only; status checkout.
  - `POST /bookings/{booking}/checkout/release` - release seats + pin; reason `cancelled` or `navigated_away`.
  - `PATCH /bookings/{booking}/checkout/group` - update participants/student_count, re-check capacity, price and skill/spot.
- `storePayment`:
  - Only `checkout` -> `pending`.
  - Clear the checkout hold.
  - Set `accept_deadline_at`.
  - Create/update the payment record.
  - Dispatch `ExpireBookingAcceptanceJob` unless Instant Book.
  - Broadcast `BookingCreated` and notify the instructor.
  - If the hold already expired, do not convert; send the student back to rebook.
- New `CheckoutHoldService` for the duration formula, heartbeat, release, re-anchor and group-change validation.
- New `ExpireCheckoutHoldJob` and `bookings:expire-checkout-holds` every minute (fallback only).
- `AvailabilityService`: include `checkout` in live/slot-holding queries; enforce one instructor -> one spot per overlapping window; release/re-anchor pins on cancel/decline/expiry/timeout.
- `Instructor\BookingController` and instructor notifications: exclude `checkout`.
- `BookingObserver`: no chat for `checkout`.

### Phase 3 - Student UI

- `resources/js/Pages/Booking/Waiver.vue` and `ParentalConsent.vue`:
  - "Your seats are reserved while you complete this step" state.
  - Heartbeat every 60 seconds.
  - Release on explicit cancel and on `pagehide` via `sendBeacon`.
  - A client flag so "Back / Edit details" keeps the hold instead of releasing it.
  - Group editor that re-checks capacity and skill/spot.
- `resources/js/Pages/Student/Bookings/*`: show `checkout` as "In checkout" with "Resume checkout" (recommended; confirm if needed).
- Ensure the instructor roster and real-time notifications never show `checkout`.

### Phase 4 - Verification

- Start MySQL (Laragon) first: in the previous session MySQL was not running and the SQLite PDO driver was absent, so DB tests could not run.
- Run `php artisan migrate`.
- Run `php artisan test`.
- Run `npm run build` (vue-tsc + Vite).
- Run `./vendor/bin/pint` on changed PHP files.
- Add `CheckoutHoldTest` covering:
  - seats held at details submission,
  - L1 fills the slot with 1 seat,
  - L2 two separate 1-student bookings at the same spot,
  - L3 multiple bookings up to 5,
  - B cannot book a different spot for the same instructor/time,
  - instructor not notified/roster-hidden during checkout,
  - explicit cancel releases seats,
  - navigate-away release endpoint,
  - fallback expiry,
  - group change over capacity rejected,
  - group change skill mismatch rejected,
  - payment method converts to pending and starts the acceptance window.

## Non-negotiable rules

- No spot seat capacity and no spot instructor capacity.
- Capacity is per instructor only.
- One instructor cannot teach two different spots in overlapping time.
- No instructor notification or roster visibility during checkout.
- No automatic timeout release while the waiver page is actively heartbeating.
- Do not bypass or weaken `SafetyService`; there is no admin override of safety rules.
- Keep the existing waivers, GCash/cash, receipt verification, cancellation/change request, feedback and suspension flows intact.
- Existing bookings (already `pending`/`confirmed`) must remain valid; the migration must be additive/nullable.
- Do not silently change the spot after payment; any spot change must be a formal, notified request.

## Open items (confirm with the user before inventing behaviour)

1. Hard maximum for an idle tab that never closes (recommended: 2 hours; currently not enforced because "no auto release while signing").
2. One active checkout per student (recommended; count it toward the 3-open-request cap).
3. Countdown UI vs only "seats reserved" on the waiver page.
4. Group editor on the waiver page vs only via Back to details.
5. Back-to-edit keeps the hold (recommended).
6. Checkout visible on the student list as "In checkout" (recommended).

## Definition of done

- The capacity table is 1/2/5 everywhere: enums, safety, config, UI, tests, docs.
- A student submitting details immediately holds exactly their seats, pins the spot, and cannot lose the seats while signing.
- Other students see only the reduced seat count and can join the remaining seats only at the same spot.
- Cancel / navigate-away / close-tab releases the seats; a lost browser session releases at the fallback ceiling.
- The instructor sees the request only after the payment method is chosen, with the 2-hour acceptance window.
- Group bookings work up to 1/2/5, every participant signs, minors require guardian consent, and group changes re-check capacity and skill.
- All existing tests pass, new checkout/capacity tests pass, and `npm run build` succeeds.

