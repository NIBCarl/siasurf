<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Notifications\Events\NotificationSent;
use App\Events\LandingDataUpdated;
use App\Events\NotificationFeedUpdated;
use App\Models\Booking;
use App\Models\Certificate;
use App\Models\InstructorProfile;
use App\Models\Review;
use App\Models\SafetyIncident;
use App\Models\SurfSpot;
use App\Models\User;
use App\Observers\BookingObserver;
use App\Observers\SafetyIncidentObserver;
use App\Contracts\PaymentServiceInterface;
use App\Services\MockPaymentService;
use App\Services\PayMongoPaymentService;
use App\Services\XenditPaymentService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind PaymentServiceInterface based on configuration
        $this->app->bind(PaymentServiceInterface::class, function ($app) {
            if (! empty(config('services.xendit.secret_key'))) {
                return new XenditPaymentService();
            }

            if (! empty(config('services.paymongo.secret_key'))) {
                return new PayMongoPaymentService();
            }

            return new MockPaymentService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('app.force_https')) {
            URL::forceScheme('https');
        }

        Vite::prefetch(concurrency: 3);

        $this->configureRateLimiting();

        // Register safety incident observer
        SafetyIncident::observe(SafetyIncidentObserver::class);

        // Every confirmed booking gets exactly one private chat thread.
        Booking::observe(BookingObserver::class);

        // Broadcast lightweight nudges so public landing visitors stay in sync
        // with spot, instructor, and review updates without polling heavily.
        SurfSpot::saved(fn () => LandingDataUpdated::notify('spots'));
        SurfSpot::deleted(fn () => LandingDataUpdated::notify('spots'));
        Review::saved(fn () => LandingDataUpdated::notify('reviews'));
        Review::deleted(fn () => LandingDataUpdated::notify('reviews'));
        InstructorProfile::saved(fn () => LandingDataUpdated::notify('instructors'));
        InstructorProfile::deleted(fn () => LandingDataUpdated::notify('instructors'));
        Certificate::saved(fn () => LandingDataUpdated::notify('instructors'));
        Certificate::deleted(fn () => LandingDataUpdated::notify('instructors'));
        User::updated(function (User $user): void {
            if ($user->isInstructor() && $user->wasChanged(['name', 'avatar_path'])) {
                LandingDataUpdated::notify('instructors');
            }
        });

        Event::listen(NotificationSent::class, function (NotificationSent $event): void {
            if ($event->channel !== 'database' || ! $event->notifiable instanceof User) {
                return;
            }

            try {
                broadcast(new NotificationFeedUpdated(
                    $event->notifiable->id,
                    (string) $event->notifiable->role
                ));
            } catch (\Throwable) {
                // A dead or unconfigured socket must never fail a database notification.
            }
        });

        // Explicit registration keeps chat authorization predictable even if
        // the model/policy naming ever changes.
        Gate::policy(\App\Models\Conversation::class, \App\Policies\ConversationPolicy::class);
        Gate::policy(\App\Models\Refund::class, \App\Policies\RefundPolicy::class);
        Gate::policy(\App\Models\InstructorPayout::class, \App\Policies\InstructorPayoutPolicy::class);

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

        // Booking-scoped chat: 30 messages per minute per user. This still
        // feels instant to a person typing on a phone while stopping a loop.
        RateLimiter::for('chat', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // Public read-only APIs (availability, surf spots, reviews).
        RateLimiter::for('public-api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}

