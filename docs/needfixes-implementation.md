# needfixes.md — Implementation Notes

Status of the items in `needfixes.md`, what was implemented, and what was
deliberately deferred.

Last updated: 2026-10-30

---

## Implemented

### 1. Time countdown removed

The session countdown was rendered in three places, not just one:

| Location | What changed |
|---|---|
| `Instructor/Dashboard.vue` | Removed the "Time left" `<Countdown>` on the live-session card, plus the `ongoingTimeLeft` ref and its `setInterval`. |
| `Student/Bookings/Show.vue` | Removed the "Time left" `<Countdown>` on the in-progress card, plus the `timeRemaining` ref, timer, and prop. |
| `Admin/Dashboard.vue` | "Time left" column replaced with a **Started** column showing when the lesson began. |

Backend: dropped the `time_remaining` key from `DashboardService` (2 sites)
and `sessionTimeRemaining` from the student booking page. Deleted the now
unused `Booking::getSessionTimeRemaining()`.

**Kept deliberately:** `accept_time_remaining`. That is the 2-hour instructor
acceptance window (FR 1.9.3), a real business rule — not a session timer.

**Kept deliberately:** `started_at`. The session lifecycle still needs it for
attendance and completion timestamps.

### 2. Attendance and completion timestamps

- `attendance_marked_at` / `attendance_marked_by` already existed and were
  already written by `Booking::markAttendance()`.
- Added `completed_by` (`2026_10_30_000001`) so completion records an actor,
  matching attendance. `Booking::complete()` now takes an optional user id.

Both instructor actions therefore record **who** and **when**:

| Action | Timestamp | Actor |
|---|---|---|
| Mark attendance | `attendance_marked_at` | `attendance_marked_by` |
| Complete session | `completed_at` | `completed_by` |

### 3. Instructor levels / student levels — already correct

`InstructorLevel` (1-3) and `SkillLevel` (beginner/intermediate/advanced) both
exist and drive search, safety, and directory visibility. No change needed.

### 6. Hidden on expired SISA ID

Implemented as **derived state**, not a stored `Hidden` status. A stored flag
would go stale at midnight and need a job to flip it back; derived visibility
cannot drift.

- `User::hasVerifiedCertificate()` now ignores expired certificates.
- `InstructorSearchController` already filtered on `expires_at`; that is the
  gate that hides the instructor from students.
- New `certs:expire` command (daily 00:15) flips stored status to `Expired`
  for reporting and logs T-30 / T-0 warnings. Visibility does **not** depend
  on this command running.

**Certificate expiry is entered manually by SISA during verification.** OCR
auto-extraction was deferred: a wrong date would silently hide a working
instructor, which is worse than the manual entry it replaces.

### 8. QR code

Two independent causes, both fixed.

**Cause A — no QR was ever generated.** `storage/app/private/instructors`
contained zero files. `generateInstructorQR()` was called from exactly one
place (`Admin\InstructorController::verify()`), so any instructor activated by
seeder, edit form, or status change had `qr_code_path = null` and `/qr/{id}/image`
returned 404.

- Added `QRCodeService::ensureInstructorQR()` — idempotent, regenerates only
  when the file is missing. Both existing call sites now use it.
- Added `instructors:generate-qr` command to backfill existing rows.

**Cause B — QR encoded localhost.** The payload used `route()`, which resolves
against `APP_URL=http://localhost:8000`. A phone scanning that hits its own
localhost.

- Added `config('siasurf.qr.public_url')` (`SIASURF_PUBLIC_URL`) used only for
  QR payloads. Falls back to `route()` when unset, preserving current
  behaviour rather than breaking already-reachable environments.

**Action required:** set `SIASURF_PUBLIC_URL` in `.env` to the LAN address or
real domain, then run `php artisan instructors:generate-qr` to regenerate.

### 9. Blocked dates unclickable

Server-side was already correct — `AvailabilityService` rejects a period with
`is_available = false` on that `specific_date`. The gap was purely UI:
`<input type="date" :min="...">` cannot disable arbitrary dates.

This is **not implemented yet** — it needs a calendar component
(flatpickr / `@vuepic/vue-datepicker`) plus a `blocked_dates` endpoint. Left
for a follow-up because it is a self-contained UI change with no schema
impact.

### 11. Reserve instructor after waiver

`storeDetails()` already holds the slot for 15 minutes (the checkout hold),
which prevents double-booking during signature without hard-blocking the
calendar. The **hard** lock timing was left as-is pending confirmation of the
intended ordering; changing it reopens a race window between details and
signature.

### 15. Instructor currently on session

`BookingStatus::slotHolding()` already includes `InProgress`, so the slot was
correctly blocked. The problem was the message: students saw the generic
"That time slot is already fully booked."

- Added `AvailabilityService::unbookableReason()`, which detects a live
  session and returns a specific message with the free time derived from
  **that session's own start + duration** — not a blanket 2 hours. A lesson
  that began at 07:00 frees the instructor at 09:00, not noon.

Implementation note: it deliberately queries `Booking` directly instead of
reusing `liveBookingsOnDate()`, because that helper calls
`releaseExpiredCheckoutHolds()` — a mutation. Calling it from this read path
released the lock that had just been created for a confirmed booking (caught
by `BookingFlowTest`).

### Blocker fixed — `markAttendance()` was structurally broken

The method had three defects that made attendance recording fail outright:

1. `$request` was not captured in the `DB::transaction` closure, so
   `$request->boolean('cash_received')` threw.
2. The cash-settlement block was duplicated verbatim (twice in this method,
   once more in `completeSession`) — three copies total.
3. One copy was nested **inside** the `if ($status === Absent)` branch, so
   cash was only ever considered for absent students.

Fixed by extracting `recordCashPayment()` and calling it once, after the
attendance/status reconciliation, independent of outcome. `completeSession()`
now reuses it too.

---

## Deferred (needs clarification)

| Item | Why deferred |
|---|---|
| **7% platform fee** | Needs decisions on the fee base (subtotal vs incl. add-ons), whether it is shown as a separate line, and how cash commission is collected. |
| **Fine ladder (₱500 / 1wk / 1mo + ID confiscation)** | Replaces the current 3-strikes → 30 days model. Needs the strike-counter reset semantics confirmed. |
| **Minors & guardian accounts** | Needs the auth model decided: does a minor ever get a login, or always exist as a booking participant under a guardian account? |
| **Multi-zone instructor assignment** | Explicitly paused. Columns exist but are unused in any UI. |
| **GCash receipt / cash remittance window** | Explicitly paused. |
| **Spot selection: map + photo** | Both requested; phased (photo cards first, then map). Leaflet is installed. `latitude`/`longitude` are nullable, so cards must remain the fallback. |
| **Under-18 registration gate** | `users` has no birthdate column; needs scope decision first. |

---

## Test status

```
285 passed, 6 failed
```

The 6 failures are **pre-existing** and unrelated — they fail identically with
these changes reverted:

- `SkillLevelMatchingTest` (2) — asserts an older capacity model
- `BookingControllerTest` (2) — standard-booking duration expectations
- `InstructorOnboardingTest` (2) — onboarding redirect / email verification

`vue-tsc --noEmit` is clean.
