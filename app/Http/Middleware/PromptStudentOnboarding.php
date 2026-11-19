<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nudges a student who has never seen the surf-profile form to fill it in.
 *
 * This is a prompt, not a gate. Skipping is a first-class outcome (the
 * onboarding page records it), so nothing here blocks a student from browsing
 * or booking - it only decides where they land first.
 *
 * Applied to the dashboard entry points rather than every student page:
 * registration and email verification both funnel through /dashboard, and
 * pinning it everywhere would fight with deep links like "book this lesson".
 */
class PromptStudentOnboarding
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isStudent()) {
            return $next($request);
        }

        // Already on the form (or submitting it): never redirect in a loop.
        if ($request->routeIs('student.onboarding', 'student.onboarding.assessment', 'student.onboarding.store', 'student.onboarding.skip')) {
            return $next($request);
        }

        // Only intercept a normal page load. An Inertia visit would otherwise be
        // answered with a redirect the client has to follow anyway.
        if (! $request->isMethod('GET') || $request->expectsJson()) {
            return $next($request);
        }

        if ($user->studentProfile?->needsOnboarding()) {
            return redirect()->route('student.onboarding', [
                // So a student who was heading somewhere specific can be sent
                // back there once they save or skip.
                'intended' => $request->fullUrl(),
            ]);
        }

        return $next($request);
    }
}
