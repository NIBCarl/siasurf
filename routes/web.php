<?php

use App\Http\Controllers\Instructor\DashboardController as InstructorDashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\InstructorSearchController;
use App\Http\Controllers\Instructor\AvailabilityController;
use App\Http\Controllers\Admin\InstructorController;
use App\Http\Controllers\Admin\CertificateController;
use App\Http\Controllers\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\Admin\IncidentController;
use App\Http\Controllers\WaiverController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Instructor\BookingController as InstructorBookingController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\SurfSpotController;
use App\Http\Controllers\Admin\SurfSpotController as AdminSurfSpotController;
use App\Http\Controllers\ReportExportController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Public routes
Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
})->name('welcome');

// Instructor search and public profile routes
Route::get('/instructors', [InstructorSearchController::class, 'index'])->name('instructors.search');
Route::get('/instructors/{instructor}', [InstructorSearchController::class, 'show'])->name('instructors.profile');

// Siargao surf-spot registry (public map + JSON feed used by the booking flow)
Route::middleware('throttle:public-api')->group(function () {
    Route::get('/surf-spots', [SurfSpotController::class, 'index'])->name('surf-spots.index');
    Route::get('/api/surf-spots', [SurfSpotController::class, 'json'])->name('api.surf-spots');
});

// Walk-in QR scan route
Route::get('/qr/{instructor}', function (\App\Models\User $instructor) {
    if ($instructor->role !== 'instructor') abort(404);
    $instructor->load(['instructorProfile', 'reviewsAsInstructor']);
    return Inertia::render('Public/WalkIn/Scan', ['instructor' => $instructor]);
})->name('walkin.scan');

// Dashboard route - accessible to all authenticated users
Route::get('/dashboard', function () {
    $user = auth()->user();
    
    if ($user->hasRole('admin')) {
        return redirect()->route('admin.dashboard');
    }
    
    if ($user->hasRole('instructor')) {
        if ($user->instructorProfile && $user->instructorProfile->status === \App\Enums\InstructorStatus::Suspended) {
            return redirect()->route('instructor.suspended');
        }
        
        // If no profile or no certificates, they must onboard
        $certificateCount = $user->certificates()->count();
        if (!$user->instructorProfile || $certificateCount === 0) {
            $message = $user->instructorProfile
                ? 'Your instructor profile is saved, but no certificate has been uploaded yet. Upload at least one certificate (PDF, JPG, or PNG) to continue verification.'
                : 'Complete your instructor onboarding to continue.';

            return redirect()->route('instructor.onboarding')->with('error', $message);
        }
        
        if ($user->instructorProfile->status === \App\Enums\InstructorStatus::PendingVerification) {
            return redirect()->route('instructor.pending');
        }
        
        // Verified instructors go to their real dashboard
        return redirect()->route('instructor.dashboard');
    }

    return redirect()->route('student.dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// Student Dashboard route
Route::middleware(['auth', 'verified', 'role:student'])->get('/student/dashboard', [App\Http\Controllers\Student\DashboardController::class, 'index'])->name('student.dashboard');

// Profile routes - accessible to all authenticated users
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Student routes
Route::middleware(['auth', 'verified', 'role:student'])->prefix('student')->as('student.')->group(function () {
    // Student bookings
    Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::get('/bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
    Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');

    // Real-time location of the instructor during a live session.
    Route::get('/bookings/{booking}/location', [LocationController::class, 'show'])
        ->name('bookings.location');
    
    // Student reviews
    Route::get('/reviews', [ReviewController::class, 'index'])->name('reviews.index');
    Route::get('/bookings/{booking}/review', [ReviewController::class, 'create'])->name('reviews.create');
    Route::post('/bookings/{booking}/review', [ReviewController::class, 'store'])->name('reviews.store');
});

// Booking creation routes (for students)
Route::middleware(['auth', 'verified', 'role:student'])->prefix('bookings')->as('bookings.')->group(function () {
    // Step 1: Select instructor and enter details
    Route::get('/create/{instructor}', [BookingController::class, 'create'])->name('create');
    Route::post('/create/{instructor}/details', [BookingController::class, 'storeDetails'])->name('store-details');
    
    // Step 4: Sign waiver
    Route::get('/{booking}/waiver', [WaiverController::class, 'showLiability'])->name('waiver');
    Route::post('/{booking}/waiver', [WaiverController::class, 'storeLiability'])->name('store-waiver');
    
    // Step 4b: Parental consent (if needed)
    Route::get('/{booking}/parental-consent', [WaiverController::class, 'showParentalConsent'])->name('parental-consent');
    Route::post('/{booking}/parental-consent', [WaiverController::class, 'storeParentalConsent'])->name('store-parental-consent');
    
    // Step 5: Payment
    // BR-4.4 - a booking cannot reach payment until the liability waiver (and
    // parental consent for minors) is signed. The middleware redirects back to
    // the missing step instead of trusting the UI to keep the order.
    Route::middleware('waiver.signed')->group(function () {
        Route::get('/{booking}/payment', [BookingController::class, 'payment'])->name('payment');
        Route::post('/{booking}/payment', [BookingController::class, 'storePayment'])->name('store-payment');
    });
    
    // Step 6: Confirmation
    Route::get('/{booking}/confirmation', [BookingController::class, 'confirmation'])->name('confirmation');
    
    // Waiver status check
    Route::get('/{booking}/waiver-status', [WaiverController::class, 'status'])->name('waiver-status');
});

// Waiver download routes (authenticated users)
Route::middleware(['auth'])->prefix('waivers')->as('waivers.')->group(function () {
    Route::get('/{waiver}/download', [WaiverController::class, 'download'])->name('download');
    Route::get('/{waiver}/view', [WaiverController::class, 'view'])->name('view');
});

// Instructor routes
Route::middleware(['auth', 'verified', 'role:instructor'])->prefix('instructor')->as('instructor.')->group(function () {
    // Instructor Dashboard
    Route::get('/dashboard', [InstructorDashboardController::class, 'index'])->name('dashboard');

    // Instructor profile management
    Route::get('/profile', function () {
        return Inertia::render('Instructor/Profile/Edit');
    })->name('profile.edit');

    Route::prefix('availability')->name('availability.')->group(function () {
        Route::get('/', [AvailabilityController::class, 'index'])->name('index');
        Route::post('/weekly', [AvailabilityController::class, 'updateWeekly'])->name('weekly');
        Route::post('/block', [AvailabilityController::class, 'blockDates'])->name('block');
        Route::delete('/unblock', [AvailabilityController::class, 'unblockDates'])->name('unblock');
        Route::post('/toggle-status', [AvailabilityController::class, 'toggleStatus'])->name('toggle-status');
        // FR 1.9.3 - Instant Book auto-confirms requests on this calendar.
        Route::post('/toggle-instant-book', [AvailabilityController::class, 'toggleInstantBook'])->name('toggle-instant-book');
    });

    // Instructor bookings
    Route::get('/bookings', [InstructorBookingController::class, 'index'])->name('bookings.index');
    Route::get('/bookings/{booking}', [InstructorBookingController::class, 'show'])->name('bookings.show');
    Route::patch('/bookings/{booking}/confirm', [InstructorBookingController::class, 'confirm'])->name('bookings.confirm');
    Route::post('/bookings/{booking}/start-session', [InstructorBookingController::class, 'startSession'])->name('bookings.start-session');
    Route::post('/bookings/{booking}/complete-session', [InstructorBookingController::class, 'completeSession'])->name('bookings.complete-session');

    // Real-time location: the instructor's browser posts a GPS fix while the
    // session is in progress. Capped at 120 pings/min per instructor.
    Route::post('/bookings/{booking}/location', [LocationController::class, 'update'])
        ->middleware('throttle:location-pings')
        ->name('bookings.location.update');
    Route::post('/bookings/{booking}/request-upgrade', [InstructorBookingController::class, 'requestUpgrade'])->name('bookings.request-upgrade');

    // FR 1.9.3 - accept or decline a booking request within 2 hours
    Route::post('/bookings/{booking}/decline', [InstructorBookingController::class, 'decline'])->name('bookings.decline');

    // FR 1.9.4 - cancel or reschedule (admin approval when under 24 hours)
    Route::post('/bookings/{booking}/change-request', [InstructorBookingController::class, 'requestChange'])->name('bookings.change-request');
    Route::delete('/bookings/{booking}/change-request', [InstructorBookingController::class, 'cancelChangeRequest'])->name('bookings.change-request.cancel');

    // FR 1.10.1 - mark student attendance
    Route::post('/bookings/{booking}/attendance', [InstructorBookingController::class, 'markAttendance'])->name('bookings.attendance');

    // Pending verification page
    Route::get('/pending', function () {
        return Inertia::render('Instructor/Pending');
    })->name('pending');

    // Suspended page
    Route::get('/suspended', function () {
        return Inertia::render('Instructor/Suspended');
    })->name('suspended');

    // Onboarding page
    Route::get('/onboarding', function () {
        $user = auth()->user();
        $profile = $user->instructorProfile;

        return Inertia::render('Instructor/Onboarding', [
            'profile' => $profile ? [
                'bio' => $profile->bio,
                'level' => $profile->level->value,
                'rate_per_hour' => (float) $profile->rate_per_hour,
            ] : null,
            'uploadedCertificates' => $user->certificates()->count(),
        ]);
    })->name('onboarding');
    Route::post('/onboarding', [\App\Http\Controllers\Instructor\OnboardingController::class, 'store'])->name('onboarding.store');

    // Instructor Certificate Upload
    Route::post('/certificates', [\App\Http\Controllers\Instructor\CertificateController::class, 'store'])->name('certificates.store');
});

// Verified instructor only routes
Route::middleware(['auth', 'verified', 'role:instructor', 'verified.instructor'])->prefix('instructor')->as('instructor.verified.')->group(function () {
    // Additional verified instructor routes
});

// API routes for availability (public)
Route::get('/api/availability/{instructor}', [AvailabilityController::class, 'getAvailableSlots'])->name('api.availability');
Route::get('/api/availability/{instructor}/booked-slots', [AvailabilityController::class, 'getBookedSlots'])->name('api.availability.booked-slots');

// Admin routes
Route::middleware(['auth', 'verified', 'role:admin'])->prefix('admin')->as('admin.')->group(function () {
    // Admin dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    
    // Dashboard API endpoints
    Route::get('/api/dashboard/stats', [DashboardController::class, 'stats'])->name('api.dashboard.stats');
    Route::get('/api/dashboard/revenue', [DashboardController::class, 'revenue'])->name('api.dashboard.revenue');
    Route::get('/api/dashboard/booking-trends', [DashboardController::class, 'bookingTrends'])->name('api.dashboard.booking-trends');
    Route::get('/api/dashboard/popular-spots', [DashboardController::class, 'popularSpots'])->name('api.dashboard.popular-spots');
    Route::get('/api/dashboard/top-instructors', [DashboardController::class, 'topInstructors'])->name('api.dashboard.top-instructors');
    Route::get('/api/dashboard/activity', [DashboardController::class, 'activity'])->name('api.dashboard.activity');
    Route::get('/api/dashboard/safety-stats', [DashboardController::class, 'safetyStats'])->name('api.dashboard.safety-stats');
    Route::get('/api/dashboard/booking-distribution', [DashboardController::class, 'bookingDistribution'])->name('api.dashboard.booking-distribution');

    // Instructor management
    Route::get('/instructors', [InstructorController::class, 'index'])->name('instructors.index');
    Route::get('/instructors/create', [InstructorController::class, 'create'])->name('instructors.create');
    Route::post('/instructors', [InstructorController::class, 'store'])->name('instructors.store');
    Route::get('/instructors/{instructor}', [InstructorController::class, 'show'])->name('instructors.show');
    Route::get('/instructors/{instructor}/edit', [InstructorController::class, 'edit'])->name('instructors.edit');
    Route::put('/instructors/{instructor}', [InstructorController::class, 'update'])->name('instructors.update');
    
    // Instructor verification actions
    Route::post('/instructors/{instructor}/verify', [InstructorController::class, 'verify'])->name('instructors.verify');
    Route::post('/instructors/{instructor}/reject', [InstructorController::class, 'reject'])->name('instructors.reject');
    Route::post('/instructors/{instructor}/suspend', [InstructorController::class, 'suspend'])->name('instructors.suspend');
    Route::post('/instructors/{instructor}/reactivate', [InstructorController::class, 'reactivate'])->name('instructors.reactivate');

    // Certificate management
    Route::post('/instructors/{instructor}/certificates', [CertificateController::class, 'store'])->name('certificates.store');
    Route::get('/certificates/{certificate}', [CertificateController::class, 'show'])->name('certificates.show');
    Route::post('/certificates/{certificate}/verify', [CertificateController::class, 'verify'])->name('certificates.verify');
    Route::post('/certificates/{certificate}/reject', [CertificateController::class, 'reject'])->name('certificates.reject');
    Route::delete('/certificates/{certificate}', [CertificateController::class, 'destroy'])->name('certificates.destroy');

    // All bookings view
    Route::get('/bookings', [AdminBookingController::class, 'index'])->name('bookings.index');
    Route::get('/bookings/{booking}', [AdminBookingController::class, 'show'])->name('bookings.show');

    // FR 1.9.4 - late cancellations/reschedules awaiting SISA approval
    Route::get('/change-requests', [AdminBookingController::class, 'changeRequests'])->name('change-requests.index');
    Route::post('/change-requests/{changeRequest}/approve', [AdminBookingController::class, 'approveChangeRequest'])->name('change-requests.approve');
    Route::post('/change-requests/{changeRequest}/reject', [AdminBookingController::class, 'rejectChangeRequest'])->name('change-requests.reject');

    // Student Management
    Route::get('/students', [\App\Http\Controllers\Admin\StudentController::class, 'index'])->name('students.index');
    Route::get('/students/{user}', [\App\Http\Controllers\Admin\StudentController::class, 'show'])->name('students.show');
    Route::post('/students/{user}/toggle-status', [\App\Http\Controllers\Admin\StudentController::class, 'toggleStatus'])->name('students.toggle-status');

    // Safety incidents
    Route::get('/incidents', [IncidentController::class, 'index'])->name('incidents.index');
    Route::get('/incidents/create', [IncidentController::class, 'create'])->name('incidents.create');
    Route::get('/incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');

    // Skill Upgrade requests
    Route::get('/skill-upgrades', [\App\Http\Controllers\Admin\SkillUpgradeController::class, 'index'])->name('skill-upgrades.index');
    Route::post('/skill-upgrades/{skillUpgradeRequest}/approve', [\App\Http\Controllers\Admin\SkillUpgradeController::class, 'approve'])->name('skill-upgrades.approve');
    Route::post('/skill-upgrades/{skillUpgradeRequest}/reject', [\App\Http\Controllers\Admin\SkillUpgradeController::class, 'reject'])->name('skill-upgrades.reject');

    // Analytics
    Route::get('/analytics', [DashboardController::class, 'analytics'])->name('analytics.index');

    // Real-time session map — every lesson running on Siargao right now.
    Route::get('/live-sessions', function () {
        $service = app(\App\Services\LocationService::class);

        return Inertia::render('Admin/LiveSessions/Index', [
            'sessions' => $service->liveSessions(),
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'on_site_radius_m' => \App\Services\LocationService::ON_SITE_RADIUS_METERS,
                'stale_after_seconds' => \App\Services\LocationService::STALE_AFTER_SECONDS,
                'siargao_bounds' => \App\Services\LocationService::SIARGAO_BOUNDS,
            ],
        ]);
    })->name('live-sessions.index');

    Route::get('/api/live-sessions', [LocationController::class, 'liveSessions'])
        ->name('api.live-sessions');

    // Surf-spot registry — SISA keeps the Siargao coordinates accurate.
    Route::get('/surf-spots', [AdminSurfSpotController::class, 'index'])->name('surf-spots.index');
    Route::post('/surf-spots', [AdminSurfSpotController::class, 'store'])->name('surf-spots.store');
    Route::put('/surf-spots/{surfSpot}', [AdminSurfSpotController::class, 'update'])->name('surf-spots.update');
    Route::delete('/surf-spots/{surfSpot}', [AdminSurfSpotController::class, 'destroy'])->name('surf-spots.destroy');

    // Review moderation
    Route::post('/reviews/{review}/moderate', [ReviewController::class, 'moderate'])->name('reviews.moderate');
    
    // Report exports
    Route::get('/exports/instructor-performance', [ReportExportController::class, 'instructorPerformance'])->name('exports.instructor-performance');
    Route::get('/exports/revenue', [ReportExportController::class, 'revenue'])->name('exports.revenue');
    Route::get('/exports/session-logs', [ReportExportController::class, 'sessionLogs'])->name('exports.session-logs');
    Route::get('/exports/safety-incidents', [ReportExportController::class, 'safetyIncidents'])->name('exports.safety-incidents');
});

// Public review routes
Route::get('/api/instructors/{instructor}/reviews', [ReviewController::class, 'forInstructor'])->name('api.instructor.reviews');
Route::get('/api/reviews/recent', [ReviewController::class, 'recent'])->name('api.reviews.recent');

// Instructor review responses
Route::middleware(['auth', 'verified', 'role:instructor'])->prefix('instructor')->as('instructor.')->group(function () {
    Route::post('/reviews/{review}/respond', [ReviewController::class, 'respond'])->name('reviews.respond');
});

require __DIR__.'/auth.php';
