<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\User;
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
}
