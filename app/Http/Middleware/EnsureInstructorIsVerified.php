<?php

namespace App\Http\Middleware;

use App\Enums\InstructorStatus;
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
            if ($user->isSuspendedInstructor()) {
                return redirect()->route('instructor.suspended')
                    ->with('error', 'Your instructor account has been suspended. Please review the reason and submit an appeal if appropriate.');
            }

            if ($user->needsInstructorOnboarding()) {
                $message = match (true) {
                    $user->instructorProfile?->status === InstructorStatus::Rejected => 'Your previous instructor application was rejected. Update your details and upload valid certificates to resubmit.',
                    $user->hasInstructorProfile() => 'Your instructor profile is saved, but no certificate has been uploaded yet. Upload at least one certificate (PDF, JPG, or PNG) to continue verification.',
                    default => 'Please complete your instructor profile setup.',
                };

                return redirect()->route('instructor.onboarding')
                    ->with('error', $message);
            }

            if (! $user->isVerifiedInstructor() || $user->isPendingInstructorVerification()) {
                return redirect()->route('instructor.pending')
                    ->with('error', 'Your instructor account is pending verification by SISA admin.');
            }
        }

        return $next($request);
    }
}
