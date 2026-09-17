# SiaSurf — Remaining Implementation Plan

> Generated: May 21, 2026 | Based on full codebase analysis

---

## Priority Legend

| Tag | Meaning |
|-----|---------|
| 🔴 **P0** | Broken functionality — fix immediately |
| 🟡 **P1** | Core feature gap — implement before production |
| 🟢 **P2** | Code quality / cleanup |
| ⚪ **P3** | Nice-to-have / polish |

---

## 🔴 P0-1: Fix Instructor Suspension 500 Error

**Root cause:** `Admin\InstructorController::suspend()` (line 231) wraps the notification call in `catch (\Exception $e)`, but when a class is missing, PHP throws an `\Error` — which is not a subclass of `\Exception`. The `\Error` propagates uncaught and produces a 500 response.

The `InstructorSuspended` notification file exists at `app/Notifications/InstructorSuspended.php` with the correct namespace. The class-not-found error is likely a stale Composer autoloader cache.

### Steps

1. **Regenerate autoloader:**
   ```bash
   composer dump-autoload
   ```

2. **Fix the catch clause** in `app/Http/Controllers/Admin/InstructorController.php` (line 232):
   ```php
   // BEFORE
   } catch (\Exception $e) {
   
   // AFTER
   } catch (\Throwable $e) {
   ```

3. **Verify:** Run the failing test:
   ```bash
   php artisan test --filter=admin_can_suspend_instructor
   ```

### Files to modify
- `app/Http/Controllers/Admin/InstructorController.php` — line 232

### Effort: 5 minutes

---

## 🟡 P1-1: Fix PricingService Session Duration Mismatch

**Root cause:** `PricingService::STANDARD_HOURS = 2` but the booking system was changed to 1-hour sessions (see `IMPLEMENTATION_SUMMARY.md`). The `calculatePrice()` method multiplies by `STANDARD_HOURS`, so all prices are calculated at 2× the intended rate.

### Steps

1. Change the constant in `app/Services/PricingService.php` (line 21):
   ```php
   // BEFORE
   const STANDARD_HOURS = 2;
   
   // AFTER
   const STANDARD_HOURS = 1;
   ```

2. Update the `STANDARD_PACKAGE_PRICE` constant (line 26) if it should reflect 1-hour pricing:
   ```php
   // BEFORE
   const STANDARD_PACKAGE_PRICE = 1200.00;
   
   // AFTER (1 hour at Level 2 rate as baseline)
   const STANDARD_PACKAGE_PRICE = 800.00;
   ```

3. **Verify:** Check that `BookingController::storeDetails()` passes `duration_hours = 1` and that the pricing calculation produces correct totals.

### Files to modify
- `app/Services/PricingService.php` — lines 21, 26

### Effort: 5 minutes

---

## 🟡 P1-2: Implement Instructor Availability Conflict Detection

**Root cause:** The `IMPLEMENTATION_SUMMARY.md` lists this as high priority but it was never implemented. An instructor can be double-booked at the same date/time slot.

### Steps

1. **Add a conflict check method** to `app/Services/SafetyService.php` (or create a new `AvailabilityService`):
   ```php
   public function hasTimeConflict(int $instructorId, string $date, string $startTime, string $endTime, ?int $excludeBookingId = null): bool
   {
       $query = Booking::where('instructor_id', $instructorId)
           ->where('date', $date)
           ->whereNotIn('status', [BookingStatus::Cancelled, BookingStatus::NoShow])
           ->where(function ($q) use ($startTime, $endTime) {
               $q->whereBetween('start_time', [$startTime, $endTime])
                 ->orWhereBetween('end_time', [$startTime, $endTime])
                 ->orWhere(function ($q2) use ($startTime, $endTime) {
                     $q2->where('start_time', '<=', $startTime)
                        ->where('end_time', '>=', $endTime);
                 });
           });
       
       if ($excludeBookingId) {
           $query->where('id', '!=', $excludeBookingId);
       }
       
       return $query->exists();
   }
   ```

2. **Integrate into `BookingController::storeDetails()`** — after safety validation, before creating the booking:
   ```php
   if ($this->safetyService->hasTimeConflict(
       $instructor->id,
       $validated['date'],
       $validated['start_time'],
       $validated['end_time']
   )) {
       return back()->with('error', 'This time slot is no longer available. Please choose another time.');
   }
   ```

3. **Integrate into `BookingController::confirm()`** — before confirming, re-check for conflicts.

4. **Add frontend feedback** — after selecting a date/time, show available/unavailable slots in `Create.vue`. This requires an API endpoint that returns booked slots for a given instructor + date.

5. **Add API endpoint** in `routes/web.php`:
   ```php
   Route::get('/api/availability/{instructor}/booked-slots', [AvailabilityController::class, 'getBookedSlots'])
       ->name('api.availability.booked-slots');
   ```

### Files to modify
- `app/Services/SafetyService.php` — add `hasTimeConflict()` method
- `app/Http/Controllers/BookingController.php` — add conflict check in `storeDetails()` and `confirm()`
- `app/Http/Controllers/Instructor/AvailabilityController.php` — add `getBookedSlots()` method
- `routes/web.php` — add booked-slots API route
- `resources/js/Pages/Booking/Create.vue` — show unavailable slots in time picker

### Effort: 2–3 hours

---

## 🟡 P1-3: Replace Placeholder QR Code with Real Library

**Root cause:** `QRCodeService::generateSimpleSVG()` creates a fake SVG pattern using `md5()` — it is not a real, scannable QR code. The `composer.json` lacks `simplesoftwareio/simple-qrcode`.

### Steps

1. **Install the library:**
   ```bash
   composer require simplesoftwareio/simple-qrcode
   ```

2. **Rewrite `QRCodeService::generateInstructorQR()`:**
   ```php
   use SimpleSoftwareIO\QrCode\Facades\QrCode;
   
   public function generateInstructorQR(InstructorProfile $instructor): string
   {
       $qrData = route('walkin.scan', ['instructor' => $instructor->user_id]);
       
       $filename = "instructors/{$instructor->user_id}/qr-code.svg";
       
       $qrCode = QrCode::format('svg')
           ->size(400)
           ->margin(2)
           ->generate($qrData);
       
       Storage::disk('local')->put($filename, $qrCode);
       
       // Update the profile with the path
       $instructor->update(['qr_code_path' => $filename]);
       
       return $filename;
   }
   ```

3. **Remove the `generateSimpleSVG()` private method** — no longer needed.

4. **Generate QR codes for existing verified instructors** — create an Artisan command or add to the `verify` action in `Admin\InstructorController`:
   ```php
   // In verify() method, after setting status to Active:
   app(QRCodeService::class)->generateInstructorQR($profile);
   ```

5. **Add a console command** for batch generation:
   ```bash
   php artisan make:command GenerateInstructorQRCodes
   ```

### Files to modify
- `composer.json` — add dependency
- `app/Services/QRCodeService.php` — rewrite generation logic
- `app/Http/Controllers/Admin/InstructorController.php` — generate QR on verify
- New: `app/Console/Commands/GenerateInstructorQRCodes.php`

### Effort: 1–2 hours

---

## 🟡 P1-4: Add Board Size Recommendation

**Root cause:** The booking form collects `height` and `weight` but no board size recommendation is displayed. The `IMPLEMENTATION_SUMMARY.md` lists this as medium priority.

### Steps

1. **Add a helper to `Booking` model or create a `BoardRecommendationService`:**
   ```php
   public function getRecommendedBoardSize(): array
   {
       $weight = $this->weight; // kg
       $height = $this->height; // cm
       $skillLevel = $this->skill_level->value;
       
       // Volume formula: (weight in kg × skill factor) + base volume
       $skillFactor = match($skillLevel) {
           'beginner' => 1.1,
           'intermediate' => 0.9,
           'advanced' => 0.7,
       };
       
       $volumeLiters = round(($weight * $skillFactor) + 20);
       
       // Length recommendation
       $lengthFeet = match(true) {
           $height < 155 => "5'6\" – 6'0\"",
           $height < 170 => "6'0\" – 6'6\"",
           $height < 185 => "6'6\" – 7'0\"",
           default => "7'0\" – 7'6\"",
       };
       
       return [
           'volume_liters' => $volumeLiters,
           'length_range' => $lengthFeet,
           'type' => $skillLevel === 'beginner' ? 'Soft-top / Foamie' : 'Hard-top / Shortboard',
       ];
   }
   ```

2. **Display in `Booking/Confirmation.vue`** — show the recommendation after booking is finalized.

3. **Display in `Instructor/Bookings/Show.vue`** — show to instructor so they can prepare the right board.

### Files to modify
- `app/Models/Booking.php` — add `getRecommendedBoardSize()` method
- `resources/js/Pages/Booking/Confirmation.vue` — display recommendation
- `resources/js/Pages/Instructor/Bookings/Show.vue` — display recommendation

### Effort: 1 hour

---

## 🟢 P2-1: Convert PHPUnit Doc-Comments to Attributes

**Root cause:** Tests use `/** @test */` doc-comments which are deprecated in PHPUnit 12. The test runner shows 11 warnings.

### Steps

1. **Add import** to each test file:
   ```php
   use PHPUnit\Framework\Attributes\Test;
   ```

2. **Replace doc-comments with attributes:**
   ```php
   // BEFORE
   /** @test */
   public function admin_can_view_instructors_list()
   
   // AFTER
   #[Test]
   public function admin_can_view_instructors_list()
   ```

3. **Files to update:**
   - `tests/Feature/Http/Controllers/AdminInstructorControllerTest.php` (11 methods)
   - Any other test files using `@test` annotations

### Effort: 15 minutes

---

## 🟢 P2-2: Remove Dead `createBooking()` Method

**Root cause:** `BookingController` has a private `createBooking()` method (lines 373–407) that is never called. The `storeDetails()` method creates bookings directly inline.

### Steps

1. Delete the `createBooking()` method from `app/Http/Controllers/BookingController.php` (lines 370–407).

2. Verify no references exist:
   ```bash
   grep -r "createBooking" app/ routes/ tests/
   ```

### Files to modify
- `app/Http/Controllers/BookingController.php` — remove lines 370–407

### Effort: 2 minutes

---

## 🟢 P2-3: Clean Up Empty Directories

**Root cause:** Several directories exist but are empty, creating clutter.

### Steps

1. **Evaluate `app/DTOs/`** — the AGENTS.md blueprint calls for DTOs. Either:
   - **Option A:** Create DTO classes for the booking flow (`CreateBookingDTO`, `WaiverDTO`, `PaymentDTO`) to formalize data transfer between controllers and services.
   - **Option B:** Remove the directory if DTOs won't be used.

   *Recommendation: Option A — create at minimum a `BookingDetailsDTO`:*
   ```php
   namespace App\DTOs;
   
   readonly class BookingDetailsDTO
   {
       public function __construct(
           public string $date,
           public string $startTime,
           public string $endTime,
           public int $durationHours,
           public string $skillLevel,
           public int $studentAge,
           public float $height,
           public float $weight,
           public int $studentCount,
           public int $surfSpotId,
           public bool $hasBoard,
           public ?string $notes,
       ) {}
   }
   ```

2. **Remove `resources/js/Pages/Dashboard/`** — the root `Dashboard.vue` in `Pages/` handles the redirect logic. The empty subdirectory serves no purpose.

3. **Remove `.refact/` directory** — leftover from a refactoring tool.

### Files to modify
- Create: `app/DTOs/BookingDetailsDTO.php`
- Delete: `resources/js/Pages/Dashboard/` directory
- Delete: `.refact/` directory

### Effort: 15–30 minutes

---

## ⚪ P3-1: Add Student QR Codes for Walk-In Check-Ins

**Root cause:** The walk-in scan route (`/qr/{instructor}`) exists but there's no student-side QR code generation for quick check-ins.

### Steps

1. **Generate a unique token per student** — add a `walkin_token` column to `student_profiles` table via migration.

2. **Create a student QR endpoint:**
   ```php
   Route::get('/student/qr', [StudentController::class, 'qrCode'])
       ->middleware(['auth', 'role:student'])
       ->name('student.qr');
   ```

3. **Add QR generation in `QRCodeService`:**
   ```php
   public function generateStudentQR(StudentProfile $student): string
   {
       $qrData = json_encode([
           'type' => 'siasurf_student',
           'token' => $student->walkin_token,
           'name' => $student->user->name,
       ]);
       // ... generate QR
   }
   ```

4. **Create the walk-in check-in flow** — instructor scans student QR → creates a walk-in booking.

### Files to modify
- New migration: `add_walkin_token_to_student_profiles`
- `app/Services/QRCodeService.php` — add `generateStudentQR()`
- `resources/js/Pages/Student/QRCode.vue` — new page
- `routes/web.php` — add student QR route

### Effort: 2–3 hours

---

## ⚪ P3-2: Enhance Parental Consent UI

**Root cause:** The `IMPLEMENTATION_SUMMARY.md` notes that parental consent is implemented but may need UI improvements.

### Steps

1. **Improve `ParentalConsent.vue`:**
   - Add a progress indicator showing which waivers have been signed.
   - Add inline validation for emergency contact phone format.
   - Add a preview of the consent form before signing.
   - Improve mobile responsiveness of the signature pad.

2. **Add a reminder in the booking flow** — if student age < 18, show a clear callout early in the flow that parental consent will be required.

### Files to modify
- `resources/js/Pages/Booking/ParentalConsent.vue`
- `resources/js/Pages/Booking/Create.vue` — add minor age notice

### Effort: 1–2 hours

---

## ⚪ P3-3: Remove Star Ratings from Reviews

**Root cause:** The `IMPLEMENTATION_SUMMARY.md` lists "Remove Star Ratings" as medium priority. The current review system uses 1–5 star ratings.

### Steps

1. **Clarify requirement first** — does "remove star ratings" mean:
   - Replace with thumbs up/down?
   - Replace with a simple "recommended / not recommended"?
   - Keep stars but de-emphasize them?

2. **If replacing with thumbs up/down:**
   - Change `rating` column in `reviews` table to `recommended` (boolean).
   - Update `Review` model casts and validation.
   - Update `Create.vue` review form to use thumbs up/down buttons.
   - Update all review display components (`ReviewCard`, `InstructorProfile`, etc.).
   - Update `DashboardService` — change avg rating calculation.

3. **Migration:**
   ```php
   Schema::table('reviews', function (Blueprint $table) {
       $table->boolean('recommended')->default(true)->after('comment');
   });
   // Then drop 'rating' column in a separate migration after data migration
   ```

### Files to modify
- New migration
- `app/Models/Review.php`
- `app/Http/Controllers/ReviewController.php`
- `resources/js/Pages/Student/Reviews/Create.vue`
- All review display components
- `app/Services/DashboardService.php`

### Effort: 2–3 hours (after requirement clarification)

---

## ⚪ P3-4: Add Stormglass Scheduled Command

**Root cause:** `StormglassService` is fully implemented but there's no scheduled Artisan command to pre-fetch tide data daily. The service falls back to on-demand fetching, which is slower and may hit rate limits.

### Steps

1. **Create the command:**
   ```bash
   php artisan make:command FetchTideData
   ```

2. **Implement:**
   ```php
   public function handle(StormglassService $stormglass): void
   {
       $this->info('Fetching tide data...');
       $days = $stormglass->fetchAndCacheTideData(7);
       $this->info("Cached tide data for {$days} days.");
   }
   ```

3. **Register in `routes/console.php`:**
   ```php
   Schedule::command('tides:fetch')->dailyAt('00:30');
   ```

### Files to modify
- New: `app/Console/Commands/FetchTideData.php`
- `routes/console.php` — add schedule

### Effort: 15 minutes

---

## Summary

| # | Task | Priority | Effort |
|---|------|----------|--------|
| P0-1 | Fix suspension 500 error | 🔴 P0 | 5 min |
| P1-1 | Fix PricingService hours constant | 🟡 P1 | 5 min |
| P1-2 | Availability conflict detection | 🟡 P1 | 2–3 hrs |
| P1-3 | Real QR code generation | 🟡 P1 | 1–2 hrs |
| P1-4 | Board size recommendation | 🟡 P1 | 1 hr |
| P2-1 | PHPUnit attribute conversion | 🟢 P2 | 15 min |
| P2-2 | Remove dead createBooking() | 🟢 P2 | 2 min |
| P2-3 | Clean up empty directories | 🟢 P2 | 15–30 min |
| P3-1 | Student QR codes | ⚪ P3 | 2–3 hrs |
| P3-2 | Parental consent UI | ⚪ P3 | 1–2 hrs |
| P3-3 | Remove star ratings | ⚪ P3 | 2–3 hrs |
| P3-4 | Stormglass scheduled command | ⚪ P3 | 15 min |

**Total estimated effort:** ~10–16 hours for all tasks; ~4–5 hours for P0+P1 only.
