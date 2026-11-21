<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // Send a student who has never seen the profile prompt there first.
        // `intended` still wins, so deep links keep working - a student who
        // clicked "book this lesson" goes straight back to that page.
        $intended = $request->session()->pull('url.intended');

        if ($intended !== null) {
            return redirect($intended);
        }

        $user = $request->user();

        if ($user?->isStudent() && $user->studentProfile?->needsOnboarding()) {
            return redirect()->route('student.onboarding');
        }

        // Plain route() call: the third argument is the absolute flag, and this
        // controller previously passed it as a named argument, which Laravel
        // rejects with "Unknown named parameter $absolute". It only surfaced
        // for admins and instructors because they reach this line while
        // students are redirected to onboarding first.
        return redirect()->route('dashboard');
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
