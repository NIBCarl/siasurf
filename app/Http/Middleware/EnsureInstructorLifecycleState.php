<?php

namespace App\Http\Middleware;

use App\Enums\InstructorStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstructorLifecycleState
{
    /**
     * Keep authenticated instructors inside the page matching their current
     * onboarding, verification, or suspension state across all web routes.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isStudent() && $user->studentProfile?->status === 'banned') {
            if (! $request->routeIs('logout')) {
                Auth::guard('web')->logout();
                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }

                return redirect()->route('login')
                    ->with('error', 'Your account has been suspended by SISA administration.');
            }
        }

        if (! $user || ! $user->isInstructor()) {
            return $next($request);
        }

        // Auth endpoints and other roles' routes stay exempt so role middleware
        // still returns 403 and sign-out / verification always work.
        if ($request->routeIs(
            'logout',
            'verification.*',
            'password.*',
            'admin.*',
            'student.*',
            'bookings.*',
            'payments.webhook',
            'payments.mock-*',
            'sanctum.*',
            'storage.*',
        ) || $request->is('broadcasting/auth', 'up')) {
            return $next($request);
        }

        $user->load('instructorProfile');

        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        if ($user->isSuspendedInstructor()) {
            if ($request->routeIs('instructor.suspended', 'instructor.suspension.appeal')) {
                return $next($request);
            }

            return redirect()->route('instructor.suspended')
                ->with('error', 'Your instructor account has been suspended. Please review the reason and submit an appeal if appropriate.');
        }

        if ($user->needsInstructorOnboarding()) {
            if ($request->routeIs('instructor.onboarding', 'instructor.onboarding.store')) {
                return $next($request);
            }

            $profile = $user->instructorProfile;

            $message = match (true) {
                $profile?->status === InstructorStatus::Rejected => 'Your previous instructor application was rejected. Update your details and upload valid certificates to resubmit.',
                $profile !== null => 'Your instructor profile is saved, but no certificate has been uploaded yet. Upload at least one certificate (PDF, JPG, or PNG) to continue verification.',
                default => 'Complete your instructor onboarding to continue.',
            };

            return redirect()->route('instructor.onboarding')
                ->with('error', $message);
        }

        if ($user->isPendingInstructorVerification()) {
            if ($request->routeIs('instructor.pending', 'instructor.certificates.store')) {
                return $next($request);
            }

            return redirect()->route('instructor.pending')
                ->with('error', 'Your instructor account is pending verification by SISA admin.');
        }

        if ($user->isVerifiedInstructor() && $request->routeIs(
            'instructor.onboarding',
            'instructor.onboarding.store',
            'instructor.pending',
            'instructor.suspended',
            'instructor.suspension.appeal',
        )) {
            return redirect()->route('instructor.dashboard');
        }

        return $next($request);
    }
}
