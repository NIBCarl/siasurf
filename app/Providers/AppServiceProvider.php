<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\User;
use App\Models\SafetyIncident;
use App\Observers\SafetyIncidentObserver;
use App\Contracts\PaymentServiceInterface;
use App\Services\MockPaymentService;
use App\Services\PayMongoPaymentService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind PaymentServiceInterface based on configuration
        $this->app->bind(PaymentServiceInterface::class, function ($app) {
            // Use MockPaymentService if no PayMongo keys configured
            if (empty(config('services.paymongo.secret_key'))) {
                return new MockPaymentService();
            }
            
            return new PayMongoPaymentService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        $this->configureRateLimiting();

        // Register safety incident observer
        SafetyIncident::observe(SafetyIncidentObserver::class);

        // Define gates for role-based access
        Gate::define('admin', function (User $user) {
            return $user->isAdmin();
        });

        Gate::define('instructor', function (User $user) {
            return $user->isInstructor();
        });

        Gate::define('student', function (User $user) {
            return $user->isStudent();
        });

        Gate::define('verified-instructor', function (User $user) {
            return $user->isVerifiedInstructor();
        });
    }

    /**
     * Phase 13 — the security checklist requires explicit limits on the
     * endpoints that either guess credentials or move money.
     *
     * Named limiters are referenced from routes with `throttle:<name>`, so the
     * window and ceiling live in one place instead of magic numbers per route.
     */
    private function configureRateLimiting(): void
    {
        // Login: 5 attempts per minute per email+IP. Stops credential stuffing
        // without locking out a user who simply mistyped a password.
        RateLimiter::for('login', function (Request $request) {
            $key = mb_strtolower((string) $request->input('email')) . '|' . $request->ip();

            return Limit::perMinute(5)->by($key)->response(
                fn () => back()->withErrors([
                    'email' => 'Too many login attempts. Please wait a minute and try again.',
                ])
            );
        });

        // Payments: 10 per minute per user. A payment intent costs an external
        // API call, so this also protects the PayMongo quota.
        RateLimiter::for('payments', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // Booking creation: 20 per minute. Keeps the abuse guard
        // (BookingRequestGuard) from being hammered.
        RateLimiter::for('bookings', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        // Live location pings: a GPS watch is expected every ~5s, so allow a
        // generous ceiling but still block a runaway client.
        RateLimiter::for('location-pings', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // Public read-only APIs (availability, surf spots, reviews).
        RateLimiter::for('public-api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}

