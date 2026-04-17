<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstructorNotSuspended
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
            $profile = $user->instructorProfile;

            if ($profile && $profile->isSuspended()) {
                return redirect()->route('instructor.suspended')
                    ->with('error', 'Your instructor account has been suspended. Please contact SISA admin for more information.');
            }
        }

        return $next($request);
    }
}
