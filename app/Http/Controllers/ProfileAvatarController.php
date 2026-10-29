<?php

namespace App\Http\Controllers;

use App\Services\AvatarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Dedicated profile-photo upload used by the Account settings page for both
 * students and instructors.
 */
class ProfileAvatarController extends Controller
{
    public function __construct(private readonly AvatarService $avatars) {}

    /**
     * Upload (or replace) the signed-in user's profile photo.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'avatar' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png',
                'max:'.AvatarService::MAX_KILOBYTES,
            ],
        ], [
            'avatar.required' => 'Choose a photo before saving.',
            'avatar.image' => 'Your profile photo must be an image.',
            'avatar.mimes' => 'Profile photos must be a JPG or PNG file.',
            'avatar.max' => 'Profile photos must be 2 MB or smaller.',
            'avatar.uploaded' => 'The photo could not be uploaded. The server limit is lower than 2 MB — compress the image and try again.',
        ]);

        try {
            $this->avatars->store($request->user(), $validated['avatar']);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'avatar' => 'The photo could not be saved. Check that storage/app/public is writable and try again.',
            ]);
        }

        return back()->with('success', 'Profile photo updated.');
    }

    /**
     * Remove the signed-in user's profile photo.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $this->avatars->remove($request->user());

        return back()->with('success', 'Profile photo removed.');
    }
}
