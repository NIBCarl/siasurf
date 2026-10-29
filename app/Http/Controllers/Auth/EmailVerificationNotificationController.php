<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        try {
            $request->user()->sendEmailVerificationNotification();
        } catch (\Throwable $exception) {
            // Bad SMTP credentials, blocked port 587, Gmail app-password
            // revoked... surface it instead of a 500 page.
            report($exception);

            return back()->with('status', 'verification-mail-failed');
        }

        return back()->with('status', 'verification-link-sent');
    }
}
