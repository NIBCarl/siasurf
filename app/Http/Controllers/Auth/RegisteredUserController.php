<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\NewUserRegisteredNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(Request $request): Response
    {
        $role = $request->query('role');

        return Inertia::render('Auth/Register', [
            'defaultRole' => in_array($role, ['student', 'instructor'], true) ? $role : 'student',
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role' => 'required|in:student,instructor',
            'terms_accepted' => ['required', 'accepted'],
        ], [
            'terms_accepted.required' => 'You must read and agree to the Terms of Service and Privacy Policy to create an account.',
            'terms_accepted.accepted' => 'You must read and agree to the Terms of Service and Privacy Policy to create an account.',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
        ]);

        // Assign Spatie role
        $user->assignRole($request->role);

        if ($request->role === 'student') {
            // New students always start at beginner until they complete the
            // skill pre-assessment during onboarding.
            $user->studentProfile()->create([
                'skill_level' => 'beginner',
                'is_first_time' => true,
            ]);
        }

        // Sending the verification mail is synchronous. A wrong Gmail app
        // password must NOT turn a successful registration into a 500, so the
        // failure is reported and surfaced on the verification screen instead.
        $mailSent = true;

        try {
            event(new Registered($user));
        } catch (\Throwable $exception) {
            report($exception);
            $mailSent = false;
        }

        User::query()->admins()->get()->each(function (User $admin) use ($user): void {
            try {
                $admin->notify(new NewUserRegisteredNotification($user));
            } catch (\Throwable $exception) {
                Log::warning('Failed to notify admin of new user registration.', [
                    'user_id' => $user->id,
                    'admin_id' => $admin->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        });

        Auth::login($user);

        // Send the user straight to the verification screen rather than the
        // dashboard: the dashboard would immediately bounce them here anyway,
        // and that extra hop loses the flash message.
        $redirect = redirect()->route('verification.notice');

        return $mailSent
            ? $redirect
            : $redirect->with('status', 'verification-mail-failed');
    }
}
