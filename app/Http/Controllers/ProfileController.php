<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\AvatarService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function __construct(private readonly AvatarService $avatars) {}

    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $user->fill(collect($validated)->only(['name', 'email', 'phone'])->all());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($user->isStudent() && (array_key_exists('birthday', $validated) || array_key_exists('country', $validated))) {
            $profileData = [];
            if (array_key_exists('birthday', $validated)) {
                $profileData['birthday'] = $validated['birthday'] ?: null;
                $profileData['date_of_birth'] = $validated['birthday'] ?: null;
            }
            if (array_key_exists('country', $validated)) {
                $profileData['country'] = filled($validated['country']) ? trim((string) $validated['country']) : null;
            }

            if ($user->studentProfile) {
                $user->studentProfile->update($profileData);
            } else {
                $user->studentProfile()->create(array_merge([
                    'skill_level' => 'beginner',
                    'is_first_time' => true,
                    'status' => 'active',
                ], $profileData));
            }
        }

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // Remove the profile photo with the account so storage does not keep
        // orphaned files after an account deletion.
        $this->avatars->remove($user);

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
