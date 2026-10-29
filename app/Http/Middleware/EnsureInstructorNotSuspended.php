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
                // The suspension page and appeal endpoint must stay reachable,
                // otherwise a suspended instructor would be redirected forever.
                if ($request->routeIs('instructor.suspended', 'instructor.suspension.appeal', 'logout')) {
                    return $next($request);
                }

                return redirect()->route('instructor.suspended')
                    ->with('error', 'Your instructor account has been suspended. Please review the reason and submit an appeal if appropriate.');
            }
        }

        return $next($request);
    }
}
