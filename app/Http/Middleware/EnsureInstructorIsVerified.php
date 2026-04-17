<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstructorIsVerified
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isInstructor()) {
            // Check if instructor has a profile
            if (!$user->hasInstructorProfile()) {
                return redirect()->route('instructor.onboarding')
                    ->with('error', 'Please complete your instructor profile setup.');
            }

            // Check if instructor is verified
            if (!$user->isVerifiedInstructor()) {
                return redirect()->route('instructor.pending')
                    ->with('error', 'Your instructor account is pending verification by SISA admin.');
            }
        }

        return $next($request);
    }
}
