<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Profile photo storage.
 *
 * Phase 1 uses Laravel's built-in `public` disk (storage/app/public) because
 * AWS S3 is not configured yet. Flipping DISK to `s3` later is enough: paths
 * are still relative to the disk and url() asks the disk for the full URL.
 */
class AvatarService
{
    /** Disk that holds avatar files. */
    public const DISK = 'public';

    /** Folder inside the disk. Every delete is scoped to this folder. */
    public const DIRECTORY = 'avatars';

    /** Largest accepted avatar, in kilobytes (2 MB). */
    public const MAX_KILOBYTES = 2048;

    /**
     * Store a new avatar and remove the previous one.
     */
    public function store(User $user, UploadedFile $file): string
    {
        $extension = strtolower($file->extension() ?: 'jpg');

        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $path = $file->storeAs(
            self::DIRECTORY,
            sprintf('user-%d-%s.%s', $user->id, Str::uuid()->toString(), $extension),
            self::DISK
        );

        if ($path === false) {
            throw new \RuntimeException('The profile photo could not be written to the public disk.');
        }

        $this->delete($user->avatar_path);

        $user->forceFill(['avatar_path' => $path])->save();

        return $path;
    }

    /**
     * Remove the user's avatar from storage and clear the column.
     */
    public function remove(User $user): void
    {
        $this->delete($user->avatar_path);

        $user->forceFill(['avatar_path' => null])->save();
    }

    /**
     * Resolve a stored avatar path to a URL the browser can load.
     *
     * The public disk is served through the /storage symlink; a relative URL is
     * used so the image also works when the demo is opened from another device
     * on the LAN. Other disks (S3) provide their own absolute URL.
     */
    public static function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        if (self::DISK === 'public') {
            return '/storage/'.ltrim($path, '/');
        }

        return Storage::disk(self::DISK)->url($path);
    }

    /**
     * Delete a stored avatar file.
     *
     * The path is constrained to the avatars directory so a tampered
     * avatar_path can never delete unrelated files on the disk.
     */
    public function delete(?string $path): void
    {
        if ($path === null || $path === '' || preg_match('#^https?://#i', $path)) {
            return;
        }

        $path = ltrim($path, '/');

        if (! str_starts_with($path, self::DIRECTORY.'/')) {
            return;
        }

        $disk = Storage::disk(self::DISK);

        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }
}
