<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
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
            'skill_level' => 'required_if:role,student|in:beginner,intermediate,advanced',
            'is_first_time' => 'required_if:role,student|boolean',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
        ]);

        // Assign Spatie role
        $user->assignRole($request->role);
        
        if ($request->role === 'instructor') {
            $user->instructorProfile()->create([
                'status' => \App\Enums\InstructorStatus::PendingVerification,
                'level' => \App\Enums\InstructorLevel::Level1,
                'rate_per_hour' => 600, // Default minimum rate
            ]);
        } elseif ($request->role === 'student') {
            $user->studentProfile()->create([
                'skill_level' => $request->skill_level ?? 'beginner',
                'is_first_time' => $request->is_first_time ?? true,
            ]);
        }

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
